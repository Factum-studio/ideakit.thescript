<?php

declare(strict_types=1);

namespace tests\unit\modules\telegram\infrastructure\transport\callback;

use Codeception\Test\Unit;
use InvalidArgumentException;
use modules\telegram\infrastructure\transport\callback\CallbackAction;
use modules\telegram\infrastructure\transport\callback\CallbackSigningKeys;
use modules\telegram\infrastructure\transport\callback\InvalidCallbackDataException;
use modules\telegram\infrastructure\transport\callback\TelegramCallbackCodec;
use modules\telegram\infrastructure\transport\callback\VerifiedCallback;

final class TelegramCallbackCodecTest extends Unit
{
    private const CARD_UUID = '018f067c-1a2b-7c3d-8e4f-123456789abc';
    private const PROFILE_UUID = '018f067c-1a2b-7c3d-8e4f-123456789abd';
    private const VECTOR = '1.d.AY8GfBorfD2OTxI0VniavA.sgXaw63ITxtzZbswbJgfeA';

    public function testActionCodesAndCardSubjects(): void
    {
        foreach ([
            [CallbackAction::DETAILS, 'd'],
            [CallbackAction::ORDER, 'o'],
            [CallbackAction::PDF, 'p'],
        ] as [$action, $code]) {
            self::assertSame($code, $action->value);
            self::assertTrue($action->usesCardSubject());
            $callback = VerifiedCallback::card($action, strtoupper(self::CARD_UUID));
            self::assertSame($action, $callback->action);
            self::assertSame(self::CARD_UUID, $callback->subject);
        }
    }

    public function testRevisionSubjects(): void
    {
        foreach ([CallbackAction::CONFIRM_ORDER, CallbackAction::CANCEL_ORDER] as $action) {
            self::assertFalse($action->usesCardSubject());
            self::assertSame(0, VerifiedCallback::revision($action, 0)->subject);
            self::assertSame(PHP_INT_MAX, VerifiedCallback::revision($action, PHP_INT_MAX)->subject);
        }
    }

    /**
     * @dataProvider invalidSubject
     */
    public function testRejectsMismatchedOrInvalidSubject(CallbackAction $action, string|int $subject): void
    {
        $this->expectException(InvalidArgumentException::class);

        if (is_string($subject)) {
            VerifiedCallback::card($action, $subject);
        } else {
            VerifiedCallback::revision($action, $subject);
        }
    }

    public static function invalidSubject(): array
    {
        return [
            'card action with revision' => [CallbackAction::DETAILS, 1],
            'revision action with card' => [CallbackAction::CONFIRM_ORDER, self::CARD_UUID],
            'invalid card uuid' => [CallbackAction::PDF, 'invalid-uuid'],
            'negative revision' => [CallbackAction::CANCEL_ORDER, -1],
        ];
    }

    public function testSigningKeysSignAndVerifyWithCurrentAndPrevious(): void
    {
        $current = base64_encode(str_repeat('A', 32));
        $previous = base64_encode(str_repeat('B', 32));
        $keys = CallbackSigningKeys::fromBase64($current, $previous);
        $oldKeys = CallbackSigningKeys::fromBase64($previous);
        $message = 'synthetic-message';

        self::assertSame(16, strlen($keys->sign($message)));
        self::assertTrue($keys->verify($message, $keys->sign($message)));
        self::assertTrue($keys->verify($message, $oldKeys->sign($message)));
        self::assertFalse($keys->verify($message . 'x', $oldKeys->sign($message)));
        self::assertFalse($keys->verify($message, str_repeat('x', 16)));
        self::assertFalse($oldKeys->verify($message, $keys->sign($message)));
    }

    public function testSigningKeysCannotBeSerializedOrPrintedInDebugOutput(): void
    {
        $keys = CallbackSigningKeys::fromBase64(base64_encode(str_repeat('A', 32)));
        self::assertSame([], $keys->__debugInfo());

        $this->expectExceptionMessage('callback_keys_not_serializable');
        serialize($keys);
    }

    /**
     * @dataProvider invalidKeyConfiguration
     */
    public function testRejectsInvalidKeysWithoutDisclosingInput(string $current, ?string $previous): void
    {
        try {
            CallbackSigningKeys::fromBase64($current, $previous);
            self::fail('Expected invalid key configuration');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('invalid_callback_key_config', $exception->getMessage());
            if ($current !== '') {
                self::assertStringNotContainsString($current, $exception->getMessage());
            }
            if ($previous !== null && $previous !== '') {
                self::assertStringNotContainsString($previous, $exception->getMessage());
            }
        }
    }

    public static function invalidKeyConfiguration(): array
    {
        $valid = base64_encode(str_repeat('A', 32));

        return [
            'empty current' => ['', null],
            'short current' => [base64_encode(str_repeat('A', 31)), null],
            'non-base64 current' => ['not!base64', null],
            'noncanonical current' => [rtrim($valid, '='), null],
            'empty previous' => [$valid, ''],
            'short previous' => [$valid, base64_encode(str_repeat('B', 31))],
            'same key' => [$valid, $valid],
        ];
    }

    public function testCardEncoderMatchesIndependentProtocolVector(): void
    {
        $wire = $this->codec()->encodeCard(
            CallbackAction::DETAILS,
            self::CARD_UUID,
            'test-bot',
            self::PROFILE_UUID,
        );

        self::assertSame(self::VECTOR, $wire);
        self::assertSame(49, strlen($wire));
    }

    public function testEncoderSupportsAllActionsAndExactBase36Boundaries(): void
    {
        foreach ([CallbackAction::DETAILS, CallbackAction::ORDER, CallbackAction::PDF] as $action) {
            $wire = $this->codec()->encodeCard($action, self::CARD_UUID, 'test-bot', self::PROFILE_UUID);
            self::assertSame($action->value, explode('.', $wire)[1]);
            self::assertLessThanOrEqual(64, strlen($wire));
        }

        foreach ([CallbackAction::CONFIRM_ORDER, CallbackAction::CANCEL_ORDER] as $action) {
            foreach ([0 => '0', 35 => 'z', 36 => '10', PHP_INT_MAX => '1y2p0ij32e8e7'] as $revision => $subject) {
                $wire = $this->codec()->encodeRevision($action, $revision, 'test-bot', self::PROFILE_UUID);
                self::assertSame($subject, explode('.', $wire)[2]);
                self::assertLessThanOrEqual(64, strlen($wire));
            }
        }
    }

    public function testEncoderRejectsInvalidArgumentsSafely(): void
    {
        $cases = [
            fn () => $this->codec()->encodeCard(CallbackAction::CONFIRM_ORDER, self::CARD_UUID, 'test-bot', self::PROFILE_UUID),
            fn () => $this->codec()->encodeRevision(CallbackAction::DETAILS, 1, 'test-bot', self::PROFILE_UUID),
            fn () => $this->codec()->encodeRevision(CallbackAction::CANCEL_ORDER, -1, 'test-bot', self::PROFILE_UUID),
            fn () => $this->codec()->encodeCard(CallbackAction::ORDER, 'private-invalid-uuid', 'test-bot', self::PROFILE_UUID),
            fn () => $this->codec()->encodeCard(CallbackAction::PDF, self::CARD_UUID, '', self::PROFILE_UUID),
            fn () => $this->codec()->encodeCard(CallbackAction::PDF, self::CARD_UUID, str_repeat('b', 65), self::PROFILE_UUID),
            fn () => $this->codec()->encodeCard(CallbackAction::PDF, self::CARD_UUID, 'test-bot', 'private-invalid-profile'),
        ];

        foreach ($cases as $case) {
            try {
                $case();
                self::fail('Expected invalid encoder argument');
            } catch (InvalidArgumentException $exception) {
                self::assertStringStartsWith('invalid_callback_', $exception->getMessage());
                self::assertStringNotContainsString('private-', $exception->getMessage());
            }
        }
    }

    public function testDecoderRoundTripsEveryActionAndRevisionBoundary(): void
    {
        foreach ([CallbackAction::DETAILS, CallbackAction::ORDER, CallbackAction::PDF] as $action) {
            $wire = $this->codec()->encodeCard($action, strtoupper(self::CARD_UUID), 'test-bot', strtoupper(self::PROFILE_UUID));
            $verified = $this->codec()->decode($wire, 'test-bot', self::PROFILE_UUID);
            self::assertSame($action, $verified->action);
            self::assertSame(self::CARD_UUID, $verified->subject);
        }

        foreach ([CallbackAction::CONFIRM_ORDER, CallbackAction::CANCEL_ORDER] as $action) {
            foreach ([0, 35, 36, PHP_INT_MAX] as $revision) {
                $wire = $this->codec()->encodeRevision($action, $revision, 'test-bot', self::PROFILE_UUID);
                $verified = $this->codec()->decode($wire, 'test-bot', self::PROFILE_UUID);
                self::assertSame($action, $verified->action);
                self::assertSame($revision, $verified->subject);
            }
        }
    }

    /**
     * @dataProvider malformedWire
     */
    public function testDecoderRejectsMalformedWireWithSafeError(string $wire): void
    {
        $this->assertInvalidWire($this->codec(), $wire, 'test-bot', self::PROFILE_UUID);
    }

    public static function malformedWire(): array
    {
        $signature = 'sgXaw63ITxtzZbswbJgfeA';
        $card = 'AY8GfBorfD2OTxI0VniavA';
        $prefix = '1.d.' . $card . '.';

        return [
            'empty' => [''],
            'oversized' => [str_repeat('a', 65)],
            'non-ascii' => ['1.d.' . $card . '.' . $signature . 'é'],
            'too few segments' => ['1.d.' . $card],
            'extra segment' => [self::VECTOR . '.x'],
            'empty segment' => ['1.d..' . $signature],
            'unknown version' => ['2.d.' . $card . '.' . $signature],
            'unknown action' => ['1.q.' . $card . '.' . $signature],
            'short card subject' => ['1.d.A.' . $signature],
            'card padding' => ['1.d.' . $card . '=.' . $signature],
            'card alphabet' => ['1.d.*' . substr($card, 1) . '.' . $signature],
            'noncanonical card bits' => ['1.d.' . substr($card, 0, -1) . 'B.' . $signature],
            'short signature' => [$prefix . 'A'],
            'signature padding' => [$prefix . $signature . '='],
            'signature alphabet' => [$prefix . '*' . substr($signature, 1)],
            'noncanonical signature bits' => [$prefix . substr($signature, 0, -1) . 'B'],
            'revision leading zero' => ['1.c.00.' . $signature],
            'revision uppercase' => ['1.c.Z.' . $signature],
            'revision sign' => ['1.c.-1.' . $signature],
            'revision whitespace' => ['1.c.1 .' . $signature],
            'revision overflow' => ['1.c.1y2p0ij32e8e8.' . $signature],
        ];
    }

    public function testDecoderRejectsTamperingAndDifferentTrustedContext(): void
    {
        $codec = $this->codec();
        $wire = $codec->encodeCard(CallbackAction::DETAILS, self::CARD_UUID, 'test-bot', self::PROFILE_UUID);
        $parts = explode('.', $wire);

        foreach ([
            'version' => '2',
            'action' => 'o',
            'subject' => 'AY8GfBorfD2OTxI0VniavB',
            'signature' => 'AgXaw63ITxtzZbswbJgfeA',
        ] as $field => $replacement) {
            $changed = $parts;
            $changed[['version' => 0, 'action' => 1, 'subject' => 2, 'signature' => 3][$field]] = $replacement;
            $this->assertInvalidWire($codec, implode('.', $changed), 'test-bot', self::PROFILE_UUID);
        }

        $this->assertInvalidWire($codec, $wire, 'other-bot', self::PROFILE_UUID);
        $this->assertInvalidWire($codec, $wire, 'test-bot', '018f067c-1a2b-7c3d-8e4f-123456789abe');
        $this->assertInvalidWire($codec, $wire, '', self::PROFILE_UUID);
        $this->assertInvalidWire($codec, $wire, 'test-bot', 'private-invalid-profile');
    }

    public function testKeyRotationAcceptsOnlyCurrentAndOnePreviousKey(): void
    {
        $a = $this->codec('A');
        $transition = $this->codec('A', 'B');
        $b = $this->codec('B', 'A');
        $c = $this->codec('C', 'B');
        $oldWire = $a->encodeCard(CallbackAction::PDF, self::CARD_UUID, 'test-bot', self::PROFILE_UUID);
        $newWire = $b->encodeCard(CallbackAction::PDF, self::CARD_UUID, 'test-bot', self::PROFILE_UUID);

        self::assertSame(CallbackAction::PDF, $transition->decode($newWire, 'test-bot', self::PROFILE_UUID)->action);
        self::assertSame($oldWire, $transition->encodeCard(CallbackAction::PDF, self::CARD_UUID, 'test-bot', self::PROFILE_UUID));
        self::assertSame(CallbackAction::PDF, $b->decode($oldWire, 'test-bot', self::PROFILE_UUID)->action);
        self::assertNotSame($oldWire, $newWire);
        self::assertSame(CallbackAction::PDF, $c->decode($newWire, 'test-bot', self::PROFILE_UUID)->action);
        $this->assertInvalidWire($c, $oldWire, 'test-bot', self::PROFILE_UUID);
    }

    private function assertInvalidWire(TelegramCallbackCodec $codec, string $wire, string $botKey, string $profileId): void
    {
        try {
            $codec->decode($wire, $botKey, $profileId);
            self::fail('Expected invalid callback');
        } catch (InvalidCallbackDataException $exception) {
            self::assertSame('invalid_callback_data', $exception->getMessage());
            if ($wire !== '') {
                self::assertStringNotContainsString($wire, $exception->getMessage());
            }
            self::assertStringNotContainsString('private-', $exception->getMessage());
        }
    }

    private function codec(string $key = 'A', ?string $previous = null): TelegramCallbackCodec
    {
        return new TelegramCallbackCodec(CallbackSigningKeys::fromBase64(
            base64_encode(str_repeat($key, 32)),
            $previous === null ? null : base64_encode(str_repeat($previous, 32)),
        ));
    }
}
