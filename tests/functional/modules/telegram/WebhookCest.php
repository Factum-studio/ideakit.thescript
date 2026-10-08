<?php

declare(strict_types=1);

namespace tests\functional\modules\telegram;

use Codeception\Module\Yii2;
use Codeception\Example;
use FunctionalTester;
use modules\telegram\application\enum\TelegramUpdateAcceptanceOutcome;
use modules\telegram\application\exception\TelegramUpdateAcceptanceUnavailableException;
use modules\telegram\application\port\IAcceptTelegramUpdate;
use modules\telegram\infrastructure\config\TelegramWebhookConfig;
use modules\telegram\infrastructure\config\InvalidTelegramWebhookConfigException;
use tests\fixtures\telegram\webhook\RecordingTelegramUpdateAcceptor;
use RuntimeException;
use Yii;
use Symfony\Component\Process\Process;
use yii\web\BadRequestHttpException;
use yii\web\Controller;

final class WebhookCest
{
    private Yii2 $framework;
    private array $environment;
    private array $server;
    private const BODY = '{"update_id":7,"message":{"message_id":1,"date":1,"from":{"id":11,"is_bot":false,"first_name":"Synthetic"},"chat":{"id":11,"type":"private"},"text":"hello"}}';

    public function _inject(Yii2 $framework): void
    {
        $this->framework = $framework;
    }

    public function _before(): void
    {
        $this->environment = $_ENV;
        $this->server = $_SERVER;
        $_ENV['JWT_SECRET'] = 'synthetic-functional-jwt-key-not-for-production';
    }

    public function _after(): void
    {
        $_ENV = $this->environment;
        $_SERVER = $this->server;
    }

    public function privateMessageReachesAcceptor(FunctionalTester $I): void
    {
        $output = $this->request();
        $I->assertSame(200, Yii::$app->response->statusCode);
        $I->assertSame('{"ok":true}', $output);
        $acceptor = Yii::$container->get(IAcceptTelegramUpdate::class);
        $I->assertSame(1, $acceptor->calls);
        $I->assertSame(7, $acceptor->command->telegramUpdateId);
        $I->assertSame('11', $acceptor->command->chatId);
        $I->assertSame('synthetic-bot', $acceptor->command->botKey);
        $I->assertSame(hash('sha256', self::BODY), $acceptor->command->payloadHash);
        $I->assertFalse(Yii::$app->db->isActive);
        $I->assertNull(Yii::$app->user->getIdentity(false));
        $I->assertFalse(Yii::$app->has('session', true));
    }

    public function ignoredDuplicateRequiresReceipt(FunctionalTester $I): void
    {
        Yii::$container->setSingleton(IAcceptTelegramUpdate::class, new RecordingTelegramUpdateAcceptor(TelegramUpdateAcceptanceOutcome::DUPLICATE));
        $I->assertSame('{"ok":true}', $this->request(body: '{"update_id":8}'));
        $acceptor = Yii::$container->get(IAcceptTelegramUpdate::class);
        $I->assertSame(1, $acceptor->calls);
        $I->assertNull($acceptor->command->chatId);
        $I->assertNotNull($acceptor->command->ignoredReason);
    }

    /** @dataProvider rejectedRequests */
    public function rejectsBeforeAcceptor(FunctionalTester $I, Example $case): void
    {
        $output = $this->request($case['method'], $case['path'], $case['body'], $case['headers']);
        $I->assertSame($case['status'], Yii::$app->response->statusCode);
        $I->assertSame($case['method'] === 'HEAD' ? '' : json_encode(['error' => ['code' => $case['status'], 'message' => $case['reason']]], JSON_THROW_ON_ERROR), $output);
        $I->assertSame(0, Yii::$container->get(IAcceptTelegramUpdate::class)->calls);
        if ($case['status'] === 405) {
            $I->assertSame('POST', Yii::$app->response->headers->get('Allow'));
        }
    }

    protected function rejectedRequests(): array
    {
        $cases = [];
        foreach (['GET', 'HEAD', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'] as $method) {
            $cases[] = [$method, '/telegram/webhook', [], self::BODY, 405, 'webhook_method_not_allowed'];
        }
        $cases[] = ['GET', '/telegram/webhook?r=site/index', ['HTTP_X_HTTP_METHOD_OVERRIDE' => 'POST'], self::BODY, 405, 'webhook_method_not_allowed'];
        $cases[] = ['POST', '/telegram/webhook?r=site/index', [], self::BODY, 400, 'webhook_invalid_request'];
        foreach ([null, '', 'wrong', 'bad secret', ['synthetic-webhook-secret', 'synthetic-webhook-secret'], 'synthetic-webhook-secret,synthetic-webhook-secret'] as $secret) {
            $cases[] = ['POST', '/telegram/webhook', ['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN' => $secret], self::BODY, 403, 'webhook_forbidden'];
        }
        foreach ([['CONTENT_TYPE' => 'text/plain'], ['CONTENT_TYPE' => 'application/json;charset=ascii'], ['CONTENT_TYPE' => 'application/json;charset=utf-8;charset=utf-8'], ['HTTP_CONTENT_ENCODING' => 'gzip']] as $headers) {
            $cases[] = ['POST', '/telegram/webhook', $headers, self::BODY, 415, 'webhook_unsupported_media_type'];
        }
        foreach (['', '{', '[]', '{"update_id":"7"}'] as $body) {
            $cases[] = ['POST', '/telegram/webhook', [], $body, 400, 'webhook_invalid_update'];
        }
        $cases[] = ['POST', '/telegram/webhook', ['CONTENT_LENGTH' => '1'], str_pad('{"update_id":7}', 65537), 413, 'webhook_payload_too_large'];
        return array_map(static fn (array $case): array => array_combine(['method', 'path', 'headers', 'body', 'status', 'reason'], $case), $cases);
    }

    /** @dataProvider alternatePaths */
    public function otherPathsKeepJwt(FunctionalTester $I, Example $case): void
    {
        $this->request(path: $case['path']);
        $I->assertSame(401, Yii::$app->response->statusCode);
        $I->assertSame(0, Yii::$container->get(IAcceptTelegramUpdate::class)->calls);
        $I->assertFalse(Yii::$app->db->isActive);
    }

    protected function alternatePaths(): array
    {
        return array_map(static fn (string $path): array => ['path' => $path], [
            '/telegram/webhook/', '/telegram/webhook/receive', '/telegram/webhook/extra',
            '/telegram/%77ebhook', '/index.php/telegram/webhook', '/user/index',
        ]);
    }

    public function actualPostIgnoresOverrideAndAcceptsSizeBoundary(FunctionalTester $I): void
    {
        $body = str_pad('{"update_id":7,"_method":"DELETE"}', 65536);
        $I->assertSame('{"ok":true}', $this->request(body: $body, headers: [
            'HTTP_X_HTTP_METHOD_OVERRIDE' => 'DELETE', 'CONTENT_TYPE' => 'Application/JSON; charset=UTF-8',
        ], parameters: ['_method' => 'DELETE']));
        $I->assertSame(hash('sha256', $body), Yii::$container->get(IAcceptTelegramUpdate::class)->command->payloadHash);
    }

    /** @dataProvider acceptanceFailures */
    public function failuresDoNotConfirmAcceptance(FunctionalTester $I, Example $case): void
    {
        $failure = new RuntimeException('synthetic-sensitive-detail', 0, new RuntimeException('synthetic-previous'));
        if ($case['mode'] === 'unavailable') {
            $failure = new TelegramUpdateAcceptanceUnavailableException($failure);
        }
        if ($case['mode'] === 'configuration') {
            Yii::$container->set(TelegramWebhookConfig::class, static fn () => throw new InvalidTelegramWebhookConfigException());
        } else {
            Yii::$container->setSingleton(IAcceptTelegramUpdate::class, new RecordingTelegramUpdateAcceptor(failure: $failure));
        }
        $output = $this->request();
        $I->assertSame($case['status'], Yii::$app->response->statusCode);
        $I->assertSame(['error' => ['code' => $case['status'], 'message' => $case['reason']]], json_decode($output, true, 8, JSON_THROW_ON_ERROR));
        $I->assertSame($case['mode'] === 'configuration' ? 0 : 1, Yii::$container->get(IAcceptTelegramUpdate::class)->calls);
        $I->assertFalse(str_contains($output, 'synthetic-sensitive-detail'));
    }

    protected function acceptanceFailures(): array
    {
        return [
            ['mode' => 'configuration', 'status' => 503, 'reason' => 'webhook_unavailable'],
            ['mode' => 'unavailable', 'status' => 503, 'reason' => 'webhook_unavailable'],
            ['mode' => 'unexpected', 'status' => 500, 'reason' => 'webhook_internal_error'],
        ];
    }

    public function csrfRemainsEnabledOutsideWebhook(FunctionalTester $I): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/browser-probe';
        Yii::$app->request->setBodyParams([]);
        $probe = new class ('browser-probe', Yii::$app) extends Controller {
            public function actionCheck(): string
            {
                return 'unexpected';
            }
        };
        $I->assertTrue(Yii::$app->request->enableCsrfValidation);
        $I->expectThrowable(BadRequestHttpException::class, static fn () => $probe->runAction('check'));
        $I->assertSame(0, Yii::$container->get(IAcceptTelegramUpdate::class)->calls);
    }

    public function authorizedDirectActionStillRejectsAlternatePath(FunctionalTester $I): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/telegram/webhook/receive';
        $I->expectThrowable(BadRequestHttpException::class, static fn () => Yii::$app->runAction('telegram/webhook/receive'));
        $I->assertSame(0, Yii::$container->get(IAcceptTelegramUpdate::class)->calls);
    }

    /** @dataProvider processCases */
    public function realCompositionProtectsPreActionFailures(FunctionalTester $I, Example $case): void
    {
        $runtime = sys_get_temp_dir() . '/webhook-' . bin2hex(random_bytes(8));
        mkdir($runtime, 0700);
        try {
            $process = new Process([PHP_BINARY, '-d', 'variables_order=EGPCS',
                dirname(__DIR__, 3) . '/fixtures/telegram/webhook/debug-composition.php',
                $case['environment'], $case['debug'], $case['mode'], $runtime,
            ]);
            $process->setTimeout(10);
            $process->run();
            $I->assertSame($case['status'] === 500 ? 1 : 0, $process->getExitCode());
            $I->assertSame('', $process->getErrorOutput());
            if ($case['status'] === 0) {
                $I->assertSame([
                    'debug' => $case['mode'] === 'ordinary',
                    'gii' => $case['mode'] === 'ordinary',
                    'production_active' => true,
                ], json_decode($process->getOutput(), true, 8, JSON_THROW_ON_ERROR));
            } else {
                $I->assertSame(['error' => [
                    'code' => $case['status'],
                    'message' => $case['status'] === 403 ? 'webhook_forbidden' : 'webhook_internal_error',
                ]], json_decode($process->getOutput(), true, 8, JSON_THROW_ON_ERROR));
                $I->assertFileExists($runtime . '/request.log');
                $log = file_get_contents($runtime . '/request.log');
                $I->assertSame(1, substr_count($log, 'sensitive_request.failed'));
                $I->assertFalse(str_contains($log, 'synthetic-private'));
                $I->assertDirectoryDoesNotExist($runtime . '/debug');
            }
        } finally {
            \yii\helpers\FileHelper::removeDirectory($runtime);
        }
    }

    protected function processCases(): array
    {
        return [
            ['environment' => 'dev', 'debug' => 'true', 'mode' => 'ordinary', 'status' => 0],
            ['environment' => 'dev', 'debug' => 'true', 'mode' => 'composition', 'status' => 0],
            ['environment' => 'dev', 'debug' => 'true', 'mode' => 'di', 'status' => 500],
            ['environment' => 'test', 'debug' => 'false', 'mode' => 'http', 'status' => 403],
            ['environment' => 'prod', 'debug' => 'true', 'mode' => 'di', 'status' => 500],
            ['environment' => 'prod', 'debug' => 'false', 'mode' => 'di', 'status' => 500],
        ];
    }

    private function request(string $method = 'POST', string $path = '/telegram/webhook', string $body = self::BODY, array $headers = [], array $parameters = []): string
    {
        $server = array_replace([
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN' => 'synthetic-webhook-secret',
        ], $headers);
        return (string) $this->framework->_request($method, $path, $parameters, [], $server, $body);
    }
}
