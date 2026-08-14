<?php

declare(strict_types=1);

use core\application\handler\AddUserIdentityHandler;
use core\application\handler\CreateUserHandler;
use core\application\handler\DeleteUserHandler;
use core\application\handler\FindUserHandler;
use core\application\handler\GetUserByIdentityHandler;
use core\application\handler\GetUserHandler;
use core\application\handler\RegenerateAuthKeyHandler;
use core\application\handler\UpdateUserHandler;
use core\application\port\ISecurityService;
use core\application\port\IUserIdentityRepository;
use core\application\port\IUserRepository;
use core\infrastructure\repository\DbUserIdentityRepository;
use core\infrastructure\repository\DbUserRepository;
use core\infrastructure\security\YiiSecurityService;
$container = Yii::$container;

// ---------- Репозитории ----------
$container->setSingleton(IUserRepository::class, function () {
    return new DbUserRepository();
});

$container->setSingleton(IUserIdentityRepository::class, function () {
    return new DbUserIdentityRepository();
});

// ---------- Security ----------
$container->setSingleton(ISecurityService::class, function () {
    return new YiiSecurityService();
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

