<?php

declare(strict_types=1);

use core\infrastructure\handler\JsonErrorHandler;
use core\infrastructure\logging\YiiSensitiveRequestLogger;
use modules\telegram\Module;
use modules\telegram\application\port\IAcceptTelegramUpdate;
use modules\telegram\infrastructure\config\TelegramWebhookConfig;
use modules\telegram\infrastructure\transport\http\TelegramWebhookHttpAdapter;
use modules\telegram\infrastructure\transport\http\TelegramWebhookRequest;
use modules\telegram\infrastructure\transport\http\TelegramWebhookUpdateMapper;
use modules\telegram\presentation\controller\WebhookController;
use yii\di\Container;
use yii\web\BadRequestHttpException;
use yii\web\MethodNotAllowedHttpException;

/** @return Closure(array<string, mixed>): array<string, mixed> */
return static function (array $webConfig): array {
    $webConfig['modules']['telegram'] = ['class' => Module::class];
    $webConfig['components']['urlManager']['rules'] = [
        'telegram/webhook' => 'telegram/webhook/receive',
        ...$webConfig['components']['urlManager']['rules'],
    ];
    $webConfig['components']['request']['class'] = TelegramWebhookRequest::class;
    $webConfig['components']['errorHandler'] = array_replace($webConfig['components']['errorHandler'], [
        'class' => JsonErrorHandler::class,
        'sensitivePaths' => ['/telegram/webhook'],
        'sensitiveErrorMessages' => WebhookController::ERROR_MESSAGES,
    ]);
    $definitions = &$webConfig['container']['definitions'];
    $definitions[TelegramWebhookConfig::class] = static fn (): TelegramWebhookConfig => TelegramWebhookConfig::fromEnvironment();
    $definitions[TelegramWebhookHttpAdapter::class] = static fn (Container $container): TelegramWebhookHttpAdapter => new TelegramWebhookHttpAdapter(
        static fn (): TelegramWebhookConfig => $container->get(TelegramWebhookConfig::class),
        $container->get(TelegramWebhookUpdateMapper::class),
    );
    $definitions[WebhookController::class] = static function (Container $container, array $params, array $config): WebhookController {
        return new WebhookController(
            $params[0],
            $params[1],
            $container->get(TelegramWebhookHttpAdapter::class),
            $container->get(IAcceptTelegramUpdate::class),
            new YiiSensitiveRequestLogger(Yii::$app->getLog(), [...array_values(WebhookController::ERROR_MESSAGES), 'webhook_invalid_update']),
            $config,
        );
    };
    $beforeRequest = $webConfig['on beforeRequest'];
    $webConfig['on beforeRequest'] = static function ($event) use ($beforeRequest): void {
        $request = Yii::$app->getRequest();
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        if (is_string($uri) && explode('?', $uri, 2)[0] === '/telegram/webhook') {
            if ($request->getMethod() !== 'POST') {
                Yii::$app->getResponse()->getHeaders()->set('Allow', 'POST');
                throw new MethodNotAllowedHttpException('webhook_method_not_allowed');
            }
            if (!$request instanceof TelegramWebhookRequest || !$request->isCanonicalWebhookRequest()) {
                throw new BadRequestHttpException('webhook_invalid_request');
            }
            return;
        }
        $beforeRequest($event);
    };

    $uri = $_SERVER['REQUEST_URI'] ?? '';
    if (is_string($uri) && explode('?', $uri, 2)[0] === '/telegram/webhook') {
        // Collectors must be excluded before Application bootstrap, not inside the action.
        $webConfig['bootstrap'] = array_values(array_filter($webConfig['bootstrap'], static fn ($entry): bool => !in_array($entry, ['debug', 'gii'], true)));
        unset($webConfig['modules']['debug'], $webConfig['modules']['gii']);
        $webConfig['components']['log']['traceLevel'] = 0;
        foreach ($webConfig['components']['log']['targets'] as &$target) {
            $target['logVars'] = [];
            $target['prefix'] = static fn (): string => '';
        }
    }

    return $webConfig;
};
