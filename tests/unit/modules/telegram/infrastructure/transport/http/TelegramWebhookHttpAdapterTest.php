<?php

declare(strict_types=1);

namespace tests\unit\modules\telegram\infrastructure\transport\http;

use Codeception\Test\Unit;
use modules\telegram\infrastructure\config\InvalidTelegramWebhookConfigException;
use modules\telegram\infrastructure\config\TelegramWebhookConfig;
use modules\telegram\infrastructure\transport\http\TelegramWebhookHttpAdapter;
use modules\telegram\infrastructure\transport\http\TelegramWebhookRequest;
use modules\telegram\infrastructure\transport\http\TelegramWebhookRequestException;
use modules\telegram\infrastructure\transport\http\TelegramWebhookUpdateMapper;
use modules\telegram\infrastructure\transport\update\TelegramUpdateParser;

final class TelegramWebhookHttpAdapterTest extends Unit
{
    /** @var array<string, mixed> */
    private array $server;

    protected function _before(): void
    {
        $this->server = $_SERVER;
        $_SERVER['REQUEST_URI'] = '/telegram/webhook';
        $_SERVER['QUERY_STRING'] = '';
        $_SERVER['REQUEST_METHOD'] = 'POST';
    }

    protected function _after(): void
    {
        $_SERVER = $this->server;
    }

    /** @dataProvider validMedia */
    public function testMapsUnchangedBodyAfterValidGates(string $type, ?string $encoding): void
    {
        $body = '{"update_id":0}' . str_repeat(' ', 65_521);
        $request = $this->request($body);
        $request->getHeaders()->set('Content-Type', $type);
        if ($encoding !== null) {
            $request->getHeaders()->set('Content-Encoding', $encoding);
        }
        $command = $this->adapter()->toCommand($request);
        self::assertSame('test-bot', $command->botKey);
        self::assertSame($body, $command->rawPayload);
        self::assertSame(hash('sha256', $body), $command->payloadHash);
        self::assertNull($command->chatId);
    }

    public static function validMedia(): iterable
    {
        yield ['application/json', null];
        yield ['Application/JSON; Charset=UTF-8', 'identity'];
        yield ['application/json; charset="utf-8"', 'IDENTITY'];
    }

    /** @dataProvider rejectedHeaders */
    public function testRejectedHeadersNeverReadBody(string $name, array $values, string $reason): void
    {
        $request = $this->request('{');
        $request->getHeaders()->set($name, $values);
        try {
            $this->adapter()->toCommand($request);
            self::fail('Expected header rejection.');
        } catch (TelegramWebhookRequestException $exception) {
            self::assertSame($reason, $exception->reason->value);
            self::assertSame(0, $request->reads);
            self::assertSame($reason, $exception->getMessage());
        }
    }

    public static function rejectedHeaders(): iterable
    {
        foreach ([[], [''], ['wrong'], ['test-secret '], ['test-secret', 'test-secret'],
            ['test-secret,test-secret'], [str_repeat('x', 257)]] as $values) {
            yield ['X-Telegram-Bot-Api-Secret-Token', $values, 'forbidden'];
        }
        foreach ([[], ['text/plain'], ['application/json', 'application/json'], ['application/json,application/json'],
            ['application/json; charset=ascii'], ['application/json; charset=utf-8; charset=utf-8'],
            ['application/json; boundary=x']] as $values) {
            yield ['Content-Type', $values, 'unsupported_media_type'];
        }
        foreach ([['gzip'], ['identity', 'identity'], ['identity,gzip'], ['']] as $values) {
            yield ['Content-Encoding', $values, 'unsupported_media_type'];
        }
    }

    /** @dataProvider invalidRequestTargets */
    public function testInitialGatesDoNotLoadConfiguration(string $uri, string $method): void
    {
        $_SERVER['REQUEST_URI'] = $uri;
        $_SERVER['REQUEST_METHOD'] = $method;
        $loads = 0;
        $adapter = new TelegramWebhookHttpAdapter(
            static function () use (&$loads): TelegramWebhookConfig {
                ++$loads;
                throw new InvalidTelegramWebhookConfigException();
            },
            new TelegramWebhookUpdateMapper(new TelegramUpdateParser()),
        );
        $request = $this->request('{');
        try {
            $adapter->toCommand($request);
            self::fail('Expected initial gate rejection.');
        } catch (TelegramWebhookRequestException $exception) {
            self::assertSame('invalid_request', $exception->reason->value);
            self::assertSame(0, $loads);
            self::assertSame(0, $request->reads);
        }
    }

    public static function invalidRequestTargets(): iterable
    {
        yield ['/telegram/webhook?r=other', 'POST'];
        yield ['/telegram/webhook/receive', 'POST'];
        yield ['/telegram/webhook', 'GET'];
    }

    public function testInvalidServerConfigurationIsNotAClientRejection(): void
    {
        $adapter = new TelegramWebhookHttpAdapter(
            static fn (): TelegramWebhookConfig => throw new InvalidTelegramWebhookConfigException(),
            new TelegramWebhookUpdateMapper(new TelegramUpdateParser()),
        );
        $request = $this->request('{');
        try {
            $adapter->toCommand($request);
            self::fail('Expected configuration failure.');
        } catch (InvalidTelegramWebhookConfigException) {
            self::assertSame(0, $request->reads);
        }
    }

    /** @dataProvider invalidBodies */
    public function testSizeAndParserFailuresRemainDistinct(string $body, string $reason): void
    {
        try {
            $this->adapter()->toCommand($this->request($body));
            self::fail('Expected body rejection.');
        } catch (TelegramWebhookRequestException $exception) {
            self::assertSame($reason, $exception->reason->value);
            self::assertSame($reason, $exception->getMessage());
        }
    }

    public static function invalidBodies(): iterable
    {
        yield ['', 'invalid_update'];
        yield ['{', 'invalid_update'];
        yield [str_repeat('x', 65_537), 'payload_too_large'];
    }

    private function adapter(): TelegramWebhookHttpAdapter
    {
        return new TelegramWebhookHttpAdapter(
            static fn (): TelegramWebhookConfig => new TelegramWebhookConfig('test-bot', 'test-secret'),
            new TelegramWebhookUpdateMapper(new TelegramUpdateParser()),
        );
    }

    private function request(string $body): TelegramWebhookRequest
    {
        $request = new class () extends TelegramWebhookRequest {
            public int $reads = 0;
            public function getRawBody(): string
            {
                ++$this->reads;
                return parent::getRawBody();
            }
        };
        $request->getHeaders()->removeAll();
        $request->getHeaders()->set('X-Telegram-Bot-Api-Secret-Token', 'test-secret');
        $request->getHeaders()->set('Content-Type', 'application/json');
        $request->setRawBody($body);
        return $request;
    }
}
