<?php

declare(strict_types=1);

use core\application\handler\AddUserIdentityHandler;
use core\application\handler\ChangeUserPostHandler;
use core\application\handler\ChangeUserRoleHandler;
use core\application\handler\ChangeUserStatusHandler;
use core\application\handler\CreateUserHandler;
use core\application\handler\DeleteUserHandler;
use core\application\handler\FindUserHandler;
use core\application\handler\GetUserByIdentityHandler;
use core\application\handler\GetUserHandler;
use core\application\handler\RegenerateAuthKeyHandler;
use core\application\handler\ResolveUserIdentityHandler;
use core\application\handler\UpdateUserHandler;
use core\application\port\IJwtManager;
use core\application\port\ISecurityService;
use core\application\port\ITransactionManager;
use core\application\port\IUserIdentityRepository;
use core\application\port\IUserRepository;
use core\application\useCase\AuthenticateUseCase;
use core\infrastructure\db\DbTransactionManager;
use core\infrastructure\jwt\JwtManager;
use core\infrastructure\jwt\JwtValidator;
use core\infrastructure\repository\DbUserIdentityRepository;
use core\infrastructure\repository\DbUserRepository;
use core\infrastructure\security\YiiSecurityService;
use core\security\JwtMiddleware;
use modules\platform\application\handler\DeclareMessagingTopologyHandler;
use modules\platform\application\handler\RelayOutboxHandler;
use modules\platform\application\policy\OutboxRetryPolicy;
use modules\platform\application\port\IOutboxRelayStore;
use modules\platform\application\port\IBrokerPublisher;
use modules\platform\application\port\IBrokerReceiver;
use modules\platform\application\port\IBrokerTopology;
use modules\platform\application\port\IOutboxWriter;
use modules\platform\application\route\OutboxRouteRegistry;
use modules\platform\infrastructure\db\DbOutboxWriter;
use modules\platform\infrastructure\config\OutboxRelayConfig;
use modules\platform\infrastructure\db\DbOutboxRelayStore;
use modules\platform\infrastructure\db\OutboxRelayRowMapper;
use modules\platform\infrastructure\identity\RamseyOutboxLeaseTokenGenerator;
use modules\platform\infrastructure\random\SecureRetryJitter;
use modules\platform\presentation\console\OutboxRelayController;
use modules\platform\infrastructure\rabbitmq\BrokerEnvelopeCodec;
use modules\platform\infrastructure\rabbitmq\RabbitMqConnectionConfig;
use modules\platform\infrastructure\rabbitmq\RabbitMqConnectionFactory;
use modules\platform\infrastructure\rabbitmq\RabbitMqPublisher;
use modules\platform\infrastructure\rabbitmq\RabbitMqReceiver;
use modules\platform\infrastructure\rabbitmq\RabbitMqTopology;
use modules\users\application\handler\MarkTelegramProfileBlockedHandler;
use modules\users\application\handler\ResolveTelegramIdentityHandler;
use modules\users\application\port\ITelegramIdentityProfileIdGenerator;
use modules\users\application\port\ITelegramIdentityProfileRepository;
use modules\users\application\port\ITransactionRunner;
use modules\users\application\port\IUserIdentityResolver;
use modules\users\infrastructure\core\CoreUserIdentityResolver;
use modules\users\infrastructure\db\DbTransactionRunner;
use modules\users\infrastructure\identity\RamseyTelegramIdentityProfileIdGenerator;
use modules\users\infrastructure\mapper\TelegramIdentityProfileMapper;
use modules\users\infrastructure\repository\DbTelegramIdentityProfileRepository;
use yii\db\Connection;
use yii\di\Container;

$container = Yii::$container;

// ---------- Репозитории ----------
$container->setSingleton(IUserRepository::class, function () {
    return new DbUserRepository();
});

$container->setSingleton(IUserIdentityRepository::class, function () {
    return new DbUserIdentityRepository();
});

$container->setSingleton(ITelegramIdentityProfileRepository::class, function () {
    return new DbTelegramIdentityProfileRepository(
        new TelegramIdentityProfileMapper(),
    );
});

// ---------- Transactions ----------
$container->setSingleton(ITransactionManager::class, function () {
    $db = Yii::$app->get('db');
    if (!$db instanceof Connection) {
        throw new RuntimeException('Application database connection is not configured.');
    }

    return new DbTransactionManager($db);
});

$container->setSingleton(ITransactionRunner::class, function () use ($container) {
    return new DbTransactionRunner(
        $container->get(ITransactionManager::class),
    );
});

// ---------- JWT ----------
$container->setSingleton(IJwtManager::class, function () {
    $secret = $_ENV['JWT_SECRET'] ?? null;

    if (empty($secret) || !is_string($secret)) {
        throw new RuntimeException('JWT_SECRET environment variable is not set or empty.');
    }

    $ttl    = (int) ($_ENV['JWT_TTL'] ?? 3600);
    return new JwtManager($secret, $ttl);
});

// ---------- JwtValidator ----------
$container->setSingleton(JwtValidator::class, function () use ($container) {
    return new JwtValidator(
        $container->get(
            IJwtManager::class,
        ),
    );
});

// ---------- Security ----------
$container->setSingleton(ISecurityService::class, function () {
    return new YiiSecurityService();
});

// ---------- Users module adapters ----------
$container->setSingleton(IUserIdentityResolver::class, function () use ($container) {
    return new CoreUserIdentityResolver(
        $container->get(ResolveUserIdentityHandler::class),
    );
});

$container->setSingleton(ITelegramIdentityProfileIdGenerator::class, function () {
    return new RamseyTelegramIdentityProfileIdGenerator();
});

// ---------- Platform outbox ----------
$container->setSingleton(IOutboxWriter::class, function () {
    $db = Yii::$app->get('db');
    if (!$db instanceof Connection) {
        throw new RuntimeException('Application database connection is not configured.');
    }

    return new DbOutboxWriter($db, new OutboxRouteRegistry());
});

// ---------- Platform broker ----------
$container->setSingleton(RabbitMqConnectionConfig::class, static function (): RabbitMqConnectionConfig {
    return require __DIR__ . '/rabbitmq.php';
});

$container->setSingleton(RabbitMqConnectionFactory::class, static function () use ($container): RabbitMqConnectionFactory {
    return new RabbitMqConnectionFactory($container->get(RabbitMqConnectionConfig::class));
});

$container->setSingleton(IBrokerTopology::class, static function () use ($container): IBrokerTopology {
    return new RabbitMqTopology($container->get(RabbitMqConnectionFactory::class));
});

$container->setSingleton(IBrokerPublisher::class, static function () use ($container): IBrokerPublisher {
    return new RabbitMqPublisher(
        $container->get(RabbitMqConnectionFactory::class),
        new BrokerEnvelopeCodec(),
        $container->get(RabbitMqConnectionConfig::class)->confirmTimeout,
    );
});

$container->set(IBrokerReceiver::class, static function () use ($container): IBrokerReceiver {
    return new RabbitMqReceiver(
        $container->get(RabbitMqConnectionFactory::class),
        new BrokerEnvelopeCodec(),
        $container->get(RabbitMqConnectionConfig::class)->consumerPollTimeout,
    );
});

$container->set(DeclareMessagingTopologyHandler::class, static function () use ($container): DeclareMessagingTopologyHandler {
    return new DeclareMessagingTopologyHandler($container->get(IBrokerTopology::class));
});

// ---------- Platform relay ----------
$container->setSingleton(OutboxRelayConfig::class, static function (): OutboxRelayConfig {
    return require __DIR__ . '/outbox_relay.php';
});

$container->setSingleton(IOutboxRelayStore::class, static function (): IOutboxRelayStore {
    $db = Yii::$app->get('db');
    if (!$db instanceof Connection) {
        throw new RuntimeException('Application database connection is not configured.');
    }

    return new DbOutboxRelayStore($db, new OutboxRelayRowMapper(new OutboxRouteRegistry()), new RamseyOutboxLeaseTokenGenerator());
});

$container->set(RelayOutboxHandler::class, static function () use ($container): RelayOutboxHandler {
    $settings = $container->get(OutboxRelayConfig::class)->settings;

    return new RelayOutboxHandler(
        $container->get(IOutboxRelayStore::class),
        $container->get(IBrokerPublisher::class),
        $settings,
        new OutboxRetryPolicy($settings, new SecureRetryJitter()),
    );
});

$container->set(OutboxRelayController::class, static function (Container $di, array $params, array $config): OutboxRelayController {
    return new OutboxRelayController(
        $params[0],
        $params[1],
        $di->get(RelayOutboxHandler::class),
        $di->get(OutboxRelayConfig::class)->defaultLimit,
        $config,
    );
});

// ---------- UseCase ----------
$container->setSingleton(AuthenticateUseCase::class, function () use ($container) {
    return new AuthenticateUseCase(
        $container->get(IUserRepository::class),
        $container->get(IUserIdentityRepository::class),
        $container->get(IJwtManager::class),
        $container->get(CreateUserHandler::class),
        $container->get(AddUserIdentityHandler::class),
    );
});

// ---------- Хендлеры (команды и запросы) ----------
$container->set(CreateUserHandler::class, function () use ($container) {
    return new CreateUserHandler(
        $container->get(IUserRepository::class),
        $container->get(ISecurityService::class),
    );
});

$container->set(UpdateUserHandler::class, function () use ($container) {
    return new UpdateUserHandler(
        $container->get(IUserRepository::class),
    );
});

$container->set(ChangeUserRoleHandler::class, function () use ($container) {
    return new ChangeUserRoleHandler(
        $container->get(IUserRepository::class),
    );
});

$container->set(ChangeUserPostHandler::class, function () use ($container) {
    return new ChangeUserPostHandler(
        $container->get(IUserRepository::class),
    );
});

$container->set(ChangeUserStatusHandler::class, function () use ($container) {
    return new ChangeUserStatusHandler(
        $container->get(IUserRepository::class),
    );
});

$container->set(DeleteUserHandler::class, function () use ($container) {
    return new DeleteUserHandler(
        $container->get(IUserRepository::class),
        $container->get(IUserIdentityRepository::class),
    );
});

$container->set(AddUserIdentityHandler::class, function () use ($container) {
    return new AddUserIdentityHandler(
        $container->get(IUserIdentityRepository::class),
        $container->get(IUserRepository::class),
    );
});

$container->set(GetUserHandler::class, function () use ($container) {
    return new GetUserHandler(
        $container->get(IUserRepository::class),
        $container->get(IUserIdentityRepository::class),
    );
});

$container->set(FindUserHandler::class, function () use ($container) {
    return new FindUserHandler(
        $container->get(IUserRepository::class),
    );
});

$container->set(GetUserByIdentityHandler::class, function () use ($container) {
    return new GetUserByIdentityHandler(
        $container->get(IUserIdentityRepository::class),
        $container->get(IUserRepository::class),
    );
});

$container->set(RegenerateAuthKeyHandler::class, function () use ($container) {
    return new RegenerateAuthKeyHandler(
        $container->get(IUserRepository::class),
        $container->get(ISecurityService::class),
    );
});

$container->set(ResolveUserIdentityHandler::class, function () use ($container) {
    return new ResolveUserIdentityHandler(
        $container->get(IUserIdentityRepository::class),
        $container->get(IUserRepository::class),
        $container->get(ISecurityService::class),
        $container->get(ITransactionManager::class),
    );
});

$container->set(ResolveTelegramIdentityHandler::class, function () use ($container) {
    return new ResolveTelegramIdentityHandler(
        $container->get(IUserIdentityResolver::class),
        $container->get(ITelegramIdentityProfileRepository::class),
        $container->get(ITelegramIdentityProfileIdGenerator::class),
        $container->get(ITransactionRunner::class),
    );
});

$container->set(MarkTelegramProfileBlockedHandler::class, function () use ($container) {
    return new MarkTelegramProfileBlockedHandler(
        $container->get(ITelegramIdentityProfileRepository::class),
        $container->get(ITransactionRunner::class),
    );
});

// ---------- Middleware ----------
$container->setSingleton(JwtMiddleware::class, function () use ($container) {
    return new JwtMiddleware(
        $container->get(IJwtManager::class),
        $container->get(IUserRepository::class),
    );
});
