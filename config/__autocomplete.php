<?php

declare(strict_types=1);

/**
 * This class only exists here for IDE (PHPStorm/Netbeans/...) autocompletion.
 * This file is never included anywhere.
 * Adjust this file to match classes configured in your application config, to enable IDE autocompletion for custom components.
 */

/**
 * @method static void error($message, $category = 'application')  // для JsonErrorHandler
 * @method static string getAlias($alias)                          // для DocsController, SwaggerController
 *
 * @property static \yii\di\Container $container                   // для доступа к контейнеру
 * @property static \yii\web\Application|\yii\console\Application|__Application $app
 */
class Yii
{
    /**
     * @var \yii\web\Application|\yii\console\Application|__Application
     */
    public static $app;

    /**
     * @var \yii\di\Container
     */
    public static $container;
}

/**
 * @property yii\rbac\DbManager $authManager
 * @property \yii\web\User|__WebUser $user
 */
class __Application
{
}

/**
 * @property app\models\User $identity
 */
class __WebUser
{
}
