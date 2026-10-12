<?php

declare(strict_types=1);

namespace tests\unit\modules\telegram\infrastructure\transport\http;

use Codeception\Test\Unit;
use modules\telegram\infrastructure\transport\http\TelegramWebhookRequest;
use modules\telegram\infrastructure\transport\http\TelegramWebhookRequestException;
use tests\fixtures\telegram\webhook\MeasuredBodyStream;

final class TelegramWebhookRequestTest extends Unit
{
    /** @var array<string, mixed> */
    private array $server;
    /** @var array<string, mixed> */
    private array $post;

    protected function _before(): void
    {
        $this->server = $_SERVER;
        $this->post = $_POST;
        $_SERVER['REQUEST_URI'] = '/telegram/webhook';
        $_SERVER['QUERY_STRING'] = '';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [];
    }

    protected function _after(): void
    {
        $_SERVER = $this->server;
        $_POST = $this->post;
    }

    public function testActualMethodIgnoresOverridesOnlyOnWebhookPath(): void
    {
        $request = new TelegramWebhookRequest();
        $request->getHeaders()->set('X-Http-Method-Override', 'DELETE');
        $_POST['_method'] = 'PATCH';
        self::assertSame('POST', $request->getMethod());
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_POST['_method'] = 'POST';
        $request->getHeaders()->set('X-Http-Method-Override', 'POST');
        self::assertSame('GET', $request->getMethod());
        $_SERVER['REQUEST_URI'] = '/other';
        self::assertSame('POST', $request->getMethod());
    }

    /** @dataProvider requestUris */
    public function testUsesOriginalUriInsteadOfNormalizedOrForwardedPath(string $uri, bool $valid): void
    {
        $_SERVER['REQUEST_URI'] = $uri;
        $request = new TelegramWebhookRequest();
        $request->setPathInfo('telegram/webhook');
        $request->getHeaders()->set('X-Original-Uri', '/telegram/webhook');
        self::assertSame($valid, $request->isCanonicalWebhookRequest());
    }

    public static function requestUris(): iterable
    {
        yield ['/telegram/webhook', true];
        foreach (['/telegram/webhook/', '/telegram/webhook/receive', '/telegram/webhook/x',
            '/telegram/%77ebhook', '/index.php/telegram/webhook', '//telegram/webhook',
            '/telegram/webhook?r=other', '/telegram/webhook?', '/telegram/webhook#fragment'] as $uri) {
            yield [$uri, false];
        }
    }

    /** @dataProvider bodySizes */
    public function testInjectedBodyIsCheckedByActualByteLength(int $size, bool $allowed): void
    {
        $request = new TelegramWebhookRequest();
        $request->getHeaders()->set('Content-Length', '1');
        $request->setRawBody(str_repeat('x', $size));
        if (!$allowed) {
            $this->expectException(TelegramWebhookRequestException::class);
            $this->expectExceptionMessage('payload_too_large');
        }
        self::assertSame($size, strlen($request->getRawBody()));
    }

    public static function bodySizes(): iterable
    {
        yield [65_536, true];
        yield [65_537, false];
    }

    public function testBoundedStreamIsCachedAndClosed(): void
    {
        $request = new class () extends TelegramWebhookRequest {
            public int $opens = 0;
            /** @var resource|null */
            public $stream = null;

            protected function openBodyStream()
            {
                ++$this->opens;
                $this->stream = fopen('php://temp', 'w+b');
                fwrite($this->stream, str_repeat('x', 65_536));
                rewind($this->stream);
                return $this->stream;
            }
        };
        $request->getHeaders()->removeAll();
        self::assertSame(65_536, strlen($request->getRawBody()));
        self::assertSame(65_536, strlen($request->getRawBody()));
        self::assertSame(1, $request->opens);
        self::assertFalse(is_resource($request->stream));
    }

    /** @dataProvider measuredStreams */
    public function testBoundedReadAndPartialFailureCloseStream(bool $fail, string $reason, int $bytes): void
    {
        MeasuredBodyStream::$failAfterFirstRead = $fail;
        stream_wrapper_register('webhookbody', MeasuredBodyStream::class);
        $request = new class () extends TelegramWebhookRequest {
            protected function openBodyStream()
            {
                return fopen('webhookbody://input', 'rb');
            }
        };
        $request->getHeaders()->removeAll();
        try {
            $request->getRawBody();
            self::fail('Expected bounded payload rejection.');
        } catch (TelegramWebhookRequestException $exception) {
            self::assertSame($reason, $exception->reason->value);
            self::assertSame($bytes, MeasuredBodyStream::$bytesRead);
            self::assertTrue(MeasuredBodyStream::$closed);
        } finally {
            stream_wrapper_unregister('webhookbody');
            MeasuredBodyStream::$failAfterFirstRead = false;
        }
    }

    public static function measuredStreams(): iterable
    {
        yield [false, 'payload_too_large', 65_537];
        yield [true, 'internal_failure', 8192];
    }

    /** @dataProvider invalidLengths */
    public function testInvalidOrOversizedLengthDoesNotOpenStream(array $values, string $reason): void
    {
        $request = new class () extends TelegramWebhookRequest {
            public int $opens = 0;
            protected function openBodyStream()
            {
                ++$this->opens;
                return false;
            }
        };
        $request->getHeaders()->set('Content-Length', $values);
        try {
            $request->getRawBody();
            self::fail('Expected content length rejection.');
        } catch (TelegramWebhookRequestException $exception) {
            self::assertSame($reason, $exception->reason->value);
            self::assertSame(0, $request->opens);
        }
    }

    public static function invalidLengths(): iterable
    {
        yield [['65537'], 'payload_too_large'];
        yield [[str_repeat('9', 30)], 'payload_too_large'];
        foreach ([['1', '2'], ['1, 2'], ['-1'], ['1.0'], ['']] as $values) {
            yield [$values, 'invalid_request'];
        }
    }

    public function testStreamFailureIsNotAnEmptyBody(): void
    {
        $request = new class () extends TelegramWebhookRequest {
            protected function openBodyStream()
            {
                return false;
            }
        };
        $request->getHeaders()->removeAll();
        $this->expectException(TelegramWebhookRequestException::class);
        $this->expectExceptionMessage('internal_failure');
        $request->getRawBody();
    }

    public function testOtherRequestKeepsFrameworkBodyBehavior(): void
    {
        $_SERVER['REQUEST_URI'] = '/other';
        $request = new TelegramWebhookRequest();
        $body = str_repeat('x', 65_537);
        $request->setRawBody($body);
        self::assertSame($body, $request->getRawBody());
    }
}
