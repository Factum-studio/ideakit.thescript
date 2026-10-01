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
use modules\platform\application\handler\ClearDeliveredOutboxPayloadHandler;
use modules\platform\application\handler\GetOutboxStatusHandler;
use modules\platform\application\handler\RecoverExpiredOutboxHandler;
use modules\platform\application\handler\RelayOutboxHandler;
use modules\platform\application\handler\RunCriticalWorkerHandler;
use modules\platform\application\port\IWorkerExecutionGuard;
use modules\platform\application\port\IWorkerRuntime;
use modules\platform\application\route\BackgroundCommandRegistry;
use modules\platform\application\policy\OutboxRetryPolicy;
use modules\platform\application\port\IOutboxRelayStore;
use modules\platform\application\port\IOutboxRecoveryStore;
use modules\platform\application\port\IOutboxPayloadCleanupStore;
use modules\platform\application\port\IOutboxStatusReader;
use modules\platform\application\port\IBrokerPublisher;
use modules\platform\application\port\IBrokerReceiver;
use modules\platform\application\port\IBrokerTopology;
use modules\platform\application\port\IOutboxWriter;
use modules\platform\application\route\OutboxRouteRegistry;
use modules\platform\application\route\OutboxRoute;
use modules\telegram\infrastructure\messaging\TelegramUpdateReceivedPayloadCodec;
use modules\platform\infrastructure\db\DbOutboxWriter;
use modules\platform\infrastructure\db\DbWorkerExecutionGuard;
use modules\platform\infrastructure\config\CriticalWorkerConfig;
use modules\platform\infrastructure\config\OutboxRelayConfig;
use modules\platform\infrastructure\db\DbOutboxRelayStore;
use modules\platform\infrastructure\db\DbOutboxRecoveryStore;
use modules\platform\infrastructure\db\DbOutboxPayloadCleanupStore;
use modules\platform\infrastructure\db\DbOutboxStatusReader;
use modules\platform\infrastructure\db\OutboxRelayRowMapper;
use modules\platform\infrastructure\identity\RamseyOutboxLeaseTokenGenerator;
use modules\platform\infrastructure\random\SecureRetryJitter;
use modules\platform\infrastructure\logging\YiiWorkerLogger;
use modules\platform\infrastructure\logging\YiiOutboxMaintenanceLogger;
use modules\platform\infrastructure\process\PcntlWorkerRuntime;
use modules\platform\presentation\console\CriticalWorkerController;
use modules\platform\presentation\console\OutboxRelayController;
use modules\platform\presentation\console\OutboxMaintenanceController;
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
use Psr\Log\LoggerInterface;

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
$container->setSingleton(OutboxRouteRegistry::class, static function (): OutboxRouteRegistry {
    return new OutboxRouteRegistry([new OutboxRoute(
        'Telegram',
        'telegram.update.received',
        '1.0',
        'TELEGRAM_UPDATE',
        'RABBITMQ',
        'critical',
        1024,
        new TelegramUpdateReceivedPayloadCodec(),
    )]);
});

$container->setSingleton(IOutboxWriter::class, function () use ($container) {
    $db = Yii::$app->get('db');
    if (!$db instanceof Connection) {
        throw new RuntimeException('Application database connection is not configured.');
    }

    return new DbOutboxWriter($db, $container->get(OutboxRouteRegistry::class));
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
        new BrokerEnvelopeCodec($container->get(OutboxRouteRegistry::class)),
        $container->get(RabbitMqConnectionConfig::class)->confirmTimeout,
        $container->get(OutboxRouteRegistry::class),
    );
});

$container->set(IBrokerReceiver::class, static function () use ($container): IBrokerReceiver {
    return new RabbitMqReceiver(
        $container->get(RabbitMqConnectionFactory::class),
        new BrokerEnvelopeCodec($container->get(OutboxRouteRegistry::class)),
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

$container->setSingleton(IOutboxRelayStore::class, static function () use ($container): IOutboxRelayStore {
    $db = Yii::$app->get('db');
    if (!$db instanceof Connection) {
        throw new RuntimeException('Application database connection is not configured.');
    }

    return new DbOutboxRelayStore($db, new OutboxRelayRowMapper($container->get(OutboxRouteRegistry::class)), new RamseyOutboxLeaseTokenGenerator());
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

$container->setSingleton(IOutboxRecoveryStore::class, static function () use ($container): IOutboxRecoveryStore {
    $db = Yii::$app->get('db');
    if (!$db instanceof Connection) {
        throw new RuntimeException('Application database connection is not configured.');
    }
    $settings = $container->get(OutboxRelayConfig::class)->settings;

    return new DbOutboxRecoveryStore(
        $db,
        new OutboxRelayRowMapper($container->get(OutboxRouteRegistry::class)),
        new OutboxRetryPolicy($settings, new SecureRetryJitter()),
    );
});

$container->set(RecoverExpiredOutboxHandler::class, static function () use ($container): RecoverExpiredOutboxHandler {
    return new RecoverExpiredOutboxHandler(
        $container->get(IOutboxRecoveryStore::class),
        $container->get(OutboxRelayConfig::class)->settings,
    );
});

$container->setSingleton(IOutboxPayloadCleanupStore::class, static function (): IOutboxPayloadCleanupStore {
    $db = Yii::$app->get('db');
    if (!$db instanceof Connection) {
        throw new RuntimeException('Application database connection is not configured.');
    }

    return new DbOutboxPayloadCleanupStore($db);
});

$container->set(ClearDeliveredOutboxPayloadHandler::class, static function () use ($container): ClearDeliveredOutboxPayloadHandler {
    return new ClearDeliveredOutboxPayloadHandler($container->get(IOutboxPayloadCleanupStore::class));
});

$container->setSingleton(IOutboxStatusReader::class, static function (): IOutboxStatusReader {
    $db = Yii::$app->get('db');
    if (!$db instanceof Connection) {
        throw new RuntimeException('Application database connection is not configured.');
    }

    return new DbOutboxStatusReader($db);
});

$container->set(GetOutboxStatusHandler::class, static function () use ($container): GetOutboxStatusHandler {
    return new GetOutboxStatusHandler($container->get(IOutboxStatusReader::class));
});

$container->set(OutboxMaintenanceController::class, static function (Container $di, array $params, array $config): OutboxMaintenanceController {
    return new OutboxMaintenanceController(
        $params[0],
        $params[1],
        $di->get(RecoverExpiredOutboxHandler::class),
        $di->get(OutboxRelayConfig::class)->defaultLimit,
        $di->get(ClearDeliveredOutboxPayloadHandler::class),
        $di->get(GetOutboxStatusHandler::class),
        new YiiOutboxMaintenanceLogger(Yii::$app->getLog()),
        $config,
    );
});

// ---------- Platform critical worker ----------
$container->setSingleton(CriticalWorkerConfig::class, static function () use ($container): CriticalWorkerConfig {
    return CriticalWorkerConfig::fromEnvironment($_ENV, $container->get(RabbitMqConnectionConfig::class));
});

$container->set(BackgroundCommandRegistry::class, static function () use ($container): BackgroundCommandRegistry {
    return new BackgroundCommandRegistry([], $container->get(OutboxRouteRegistry::class));
});

$container->set(IWorkerRuntime::class, static function (): IWorkerRuntime {
    return new PcntlWorkerRuntime();
});

$container->set(IWorkerExecutionGuard::class, static function (): IWorkerExecutionGuard {
    $db = Yii::$app->get('db');
    if (!$db instanceof Connection) {
        throw new RuntimeException('Application database connection is not configured.');
    }

    return new DbWorkerExecutionGuard($db);
});

$container->set(LoggerInterface::class, static function (): LoggerInterface {
    return new YiiWorkerLogger(Yii::$app->getLog());
});

$container->set(RunCriticalWorkerHandler::class, static function () use ($container): RunCriticalWorkerHandler {
    $settings = $container->get(CriticalWorkerConfig::class)->settings;

    return new RunCriticalWorkerHandler(
        new RabbitMqReceiver(
            $container->get(RabbitMqConnectionFactory::class),
            new BrokerEnvelopeCodec($container->get(OutboxRouteRegistry::class)),
            $container->get(RabbitMqConnectionConfig::class)->consumerPollTimeout,
            $settings->handlerTimeoutSeconds,
        ),
        $container->get(BackgroundCommandRegistry::class),
        $container->get(IWorkerRuntime::class),
        $container->get(IWorkerExecutionGuard::class),
        $settings,
        $container->get(LoggerInterface::class),
    );
});

$container->set(CriticalWorkerController::class, static function (Container $di, array $params, array $config): CriticalWorkerController {
    $workerConfig = $di->get(CriticalWorkerConfig::class);

    return new CriticalWorkerController(
        $params[0],
        $params[1],
        $di->get(RunCriticalWorkerHandler::class),
        $workerConfig->command->maxMessages,
        $workerConfig->command->maxRuntimeSeconds,
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
