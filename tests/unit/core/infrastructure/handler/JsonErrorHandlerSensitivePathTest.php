<?php

declare(strict_types=1);

namespace tests\unit\core\infrastructure\handler;

use Codeception\Test\Unit;
use core\infrastructure\handler\JsonErrorHandler;
use core\infrastructure\logging\YiiSensitiveRequestLogger;
use Psr\Log\InvalidArgumentException;
use RuntimeException;
use Stringable;
use Symfony\Component\Process\Process;
use Throwable;
use Yii;
use yii\base\Application as BaseApplication;
use yii\di\Container;
use yii\log\Logger;
use yii\log\Target;
use yii\web\Application;
use yii\web\HttpException;

final class JsonErrorHandlerSensitivePathTest extends Unit
{
    private const SENTINEL = 'synthetic-sensitive-webhook-detail';
    private const ERRORS = [
        400 => 'webhook_invalid_request', 403 => 'webhook_forbidden', 405 => 'webhook_method_not_allowed',
        413 => 'webhook_payload_too_large', 415 => 'webhook_unsupported_media_type',
        500 => 'webhook_internal_error', 503 => 'webhook_unavailable',
    ];

    private ?BaseApplication $application;
    private Container $container;
    private Logger $logger;
    /** @var array<string, mixed> */
    private array $server;
    /** @var array<string, mixed> */
    private array $environment;
    private Target $target;
    private string|false $ignoreTraceArguments;

    protected function _before(): void
    {
        $this->application = Yii::$app;
        $this->container = Yii::$container;
        $this->logger = Yii::getLogger();
        $this->server = $_SERVER;
        $this->environment = $_ENV;
        $this->ignoreTraceArguments = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
        $_ENV['DEBUG_LVL'] = 3;
        $_SERVER = [
            'REQUEST_URI' => '/telegram/webhook?synthetic-query', 'REQUEST_METHOD' => 'POST',
            'HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN' => self::SENTINEL,
        ];
        Yii::$container = new Container();
        Yii::setLogger(new Logger(['flushInterval' => 0, 'traceLevel' => 3]));
        $this->target = new class (['logVars' => ['_SERVER'], 'prefix' => static fn (): string => self::SENTINEL]) extends Target {
            /** @var list<string> */
            public array $records = [];

            public function export(): void
            {
                foreach ($this->messages as $message) {
                    $this->records[] = $this->formatMessage($message);
                }
            }
        };
        new Application([
            'id' => 'sensitive-error-test',
            'basePath' => dirname(__DIR__, 5),
            'components' => [
                'request' => [
                    'cookieValidationKey' => 'synthetic-test-key', 'scriptUrl' => '/index.php',
                    'scriptFile' => dirname(__DIR__, 5) . '/web/index.php',
                ],
                'log' => ['targets' => [$this->target]],
            ],
        ]);
        Yii::$app->getLog();
        Yii::$app->request->setRawBody(self::SENTINEL);
    }

    protected function _after(): void
    {
        Yii::getLogger()->messages = [];
        Yii::$app = $this->application;
        Yii::$container = $this->container;
        Yii::setLogger($this->logger);
        $_SERVER = $this->server;
        $_ENV = $this->environment;
        if ($this->ignoreTraceArguments !== false) {
            ini_set('zend.exception_ignore_args', $this->ignoreTraceArguments);
        }
    }

    public function testSensitiveResponseDoesNotExposeExceptionOrRequest(): void
    {
        $output = $this->handle(self::failure(self::SENTINEL));
        self::assertFalse(str_contains($output, self::SENTINEL));
        self::assertSame(['error' => ['code' => 500, 'message' => 'webhook_internal_error']], json_decode($output, true, 8, JSON_THROW_ON_ERROR));
    }

    public function testAutomaticLoggingDoesNotExposeExceptionOrRequest(): void
    {
        $this->target->messages = [[self::SENTINEL, Logger::LEVEL_TRACE, 'application', microtime(true), [], 0]];
        $this->handle(self::failure(self::SENTINEL));
        self::assertFalse(str_contains(implode("\n", $this->target->records), self::SENTINEL));
        self::assertCount(1, $this->target->records);
        self::assertStringContainsString('sensitive_request.failed', $this->target->records[0]);
        self::assertStringContainsString('webhook_internal_error', $this->target->records[0]);
        self::assertMatchesRegularExpression('/[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}/', $this->target->records[0]);
        self::assertSame(['_SERVER'], $this->target->logVars);
        self::assertSame(self::SENTINEL, ($this->target->prefix)([]));
    }

    /** @dataProvider httpErrors */
    public function testHttpErrorsUseOnlyConfiguredCodes(int $status, int $expected): void
    {
        $output = $this->handle(new HttpException($status, self::SENTINEL));
        self::assertSame(['error' => ['code' => $expected, 'message' => self::ERRORS[$expected]]], json_decode($output, true, 8, JSON_THROW_ON_ERROR));
        self::assertSame($expected, Yii::$app->response->statusCode);
        self::assertSame($expected === 405 ? 'POST' : null, Yii::$app->response->headers->get('Allow'));
    }

    public static function httpErrors(): iterable
    {
        foreach (array_keys(self::ERRORS) as $status) {
            yield [$status, $status];
        }
        yield [418, 500];
    }

    public function testHeadHasNoBodyEvenWithMethodOverride(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'HEAD';
        Yii::$app->request->headers->set('X-Http-Method-Override', 'POST');
        self::assertSame('', $this->handle(new HttpException(405, self::SENTINEL)));
        self::assertSame(405, Yii::$app->response->statusCode);
        self::assertSame('POST', Yii::$app->response->headers->get('Allow'));
    }

    /** @dataProvider ordinaryPaths */
    public function testPathMatchingIsExactAndDisabledByDefault(string $path, bool $enabled): void
    {
        $_SERVER['REQUEST_URI'] = $path;
        $output = $this->handle(new HttpException(400, self::SENTINEL), $enabled);
        self::assertSame(self::SENTINEL, json_decode($output, true, 32, JSON_THROW_ON_ERROR)['error']['message']);
    }

    public static function ordinaryPaths(): iterable
    {
        yield ['/telegram/webhook', false];
        yield ['/telegram/webhook/', true];
        yield ['/telegram/webhook/receive', true];
        yield ['/telegram/%77ebhook', true];
    }

    public function testLoggerRejectsUnapprovedFieldsWithoutStringifyingObjects(): void
    {
        $logger = new YiiSensitiveRequestLogger(Yii::$app->getLog(), array_values(self::ERRORS));
        $logger->error('sensitive_request.failed', [
            'reason' => 'webhook_internal_error', 'http_status' => 500,
            'correlation_id' => 'e63870fb-1193-45f4-8eac-bd2665eab613',
            'exception' => self::failure(self::SENTINEL), 'payload' => self::SENTINEL,
        ]);
        self::assertCount(1, $this->target->records);
        self::assertFalse(str_contains($this->target->records[0], self::SENTINEL));
        self::assertStringNotContainsString(' in /', $this->target->records[0]);
        $logger->error('sensitive_request.failed', [
            'reason' => self::SENTINEL, 'http_status' => self::SENTINEL, 'correlation_id' => self::SENTINEL,
        ]);
        self::assertFalse(str_contains($this->target->records[1], self::SENTINEL));
        $logger->info(new class () implements Stringable {
            public function __toString(): string
            {
                throw new RuntimeException('unexpected_string_conversion');
            }
        });
        $logger->info(self::SENTINEL);
        self::assertCount(2, $this->target->records);
        try {
            $logger->log(self::SENTINEL, 'sensitive_request.failed');
            self::fail('Expected invalid log level.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('invalid_log_level', $exception->getMessage());
        }
    }

    /** @dataProvider fallbackModes */
    public function testFallbackDoesNotExposeEitherException(string $mode, string $environment, string $debug, string $method): void
    {
        $process = new Process([
            PHP_BINARY, '-d', 'zend.exception_ignore_args=0',
            'tests/fixtures/telegram/webhook/sensitive-error-fallback.php', $mode, $method,
        ], dirname(__DIR__, 5), ['APP_ENV' => $environment, 'APP_DEBUG' => $debug], null, 10);
        $process->run();
        self::assertSame(1, $process->getExitCode(), 'Expected error-handler termination.');
        self::assertFalse(str_contains($process->getOutput() . $process->getErrorOutput(), self::SENTINEL));
        self::assertSame($method === 'HEAD' ? '' : '{"error":{"code":500,"message":"webhook_internal_error"}}', $process->getOutput());
        self::assertMatchesRegularExpression('/\A(?:\[\d{2}-[A-Za-z]{3}-\d{4} \d{2}:\d{2}:\d{2} UTC\] )?sensitive_request\.fallback\n\z/', $process->getErrorOutput());
    }

    public static function fallbackModes(): iterable
    {
        foreach (['logger', 'formatter'] as $mode) {
            foreach (['test', 'prod'] as $environment) {
                foreach (['false', 'true'] as $debug) {
                    yield [$mode, $environment, $debug, 'POST'];
                }
            }
        }
        yield ['formatter', 'test', 'true', 'HEAD'];
    }

    public function testOrdinaryPathPreservesExistingResponseAndDiagnostics(): void
    {
        $_SERVER['REQUEST_URI'] = '/ordinary';
        $output = $this->handle(new HttpException(400, self::SENTINEL));
        $data = json_decode($output, true, 32, JSON_THROW_ON_ERROR);
        self::assertSame(400, $data['error']['code']);
        self::assertSame(self::SENTINEL, $data['error']['message']);
        self::assertArrayHasKey('request', $data['error']['details']);
        self::assertStringContainsString(self::SENTINEL, implode("\n", $this->target->records));
    }

    private function handle(Throwable $exception, bool $enabled = true): string
    {
        $handler = new JsonErrorHandler(['silentExitOnException' => true, 'discardExistingOutput' => false]);
        if ($enabled) {
            $handler->sensitivePaths = ['/telegram/webhook'];
            $handler->sensitiveErrorMessages = self::ERRORS;
        }
        ob_start();
        try {
            $handler->handleException($exception);
            Yii::getLogger()->flush(true);

            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }

    private static function failure(string $detail): Throwable
    {
        return new RuntimeException($detail, 0, new RuntimeException($detail));
    }
}
