<?php

declare(strict_types=1);

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

