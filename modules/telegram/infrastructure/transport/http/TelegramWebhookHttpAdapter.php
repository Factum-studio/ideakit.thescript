<?php

declare(strict_types=1);

namespace modules\telegram\infrastructure\transport\http;

use Closure;
use modules\telegram\application\command\AcceptTelegramUpdateCommand;
use modules\telegram\infrastructure\config\InvalidTelegramWebhookConfigException;
use modules\telegram\infrastructure\config\TelegramWebhookConfig;
use modules\telegram\infrastructure\transport\update\InvalidTelegramUpdateException;

final class TelegramWebhookHttpAdapter
{
    /** @param Closure(): TelegramWebhookConfig $configFactory */
    public function __construct(
        private readonly Closure $configFactory,
        private readonly TelegramWebhookUpdateMapper $mapper,
    ) {
    }

    /**
     * @throws TelegramWebhookRequestException
     * @throws InvalidTelegramWebhookConfigException
     */
    public function toCommand(TelegramWebhookRequest $request): AcceptTelegramUpdateCommand
    {
        if (!$request->isCanonicalWebhookRequest() || $request->getMethod() !== 'POST') {
            throw new TelegramWebhookRequestException(TelegramWebhookRejectionReason::INVALID_REQUEST);
        }

        $config = ($this->configFactory)();
        $this->validateSecret($request, $config);
        $this->validateMedia($request);
        $body = $request->getRawBody();
        if ($body === '') {
            throw new TelegramWebhookRequestException(TelegramWebhookRejectionReason::INVALID_UPDATE);
        }

        try {
            return $this->mapper->fromRawPayload($config->botKey, $body);
        } catch (InvalidTelegramUpdateException $exception) {
            throw new TelegramWebhookRequestException(TelegramWebhookRejectionReason::INVALID_UPDATE, $exception);
        }
    }

    private function validateSecret(TelegramWebhookRequest $request, TelegramWebhookConfig $config): void
    {
        /** @var array<array-key, mixed> $values Yii header injection does not enforce string values. */
        $values = $request->getHeaders()->get('X-Telegram-Bot-Api-Secret-Token', [], false);
        if (count($values) !== 1 || !isset($values[0]) || !is_string($values[0])
            || preg_match('/\A[A-Za-z0-9_-]{1,256}\z/', $values[0]) !== 1
            || !hash_equals($config->secret, $values[0])) {
            throw new TelegramWebhookRequestException(TelegramWebhookRejectionReason::FORBIDDEN);
        }
    }

    private function validateMedia(TelegramWebhookRequest $request): void
    {
        /** @var array<array-key, mixed> $types */
        $types = $request->getHeaders()->get('Content-Type', [], false);
        if (count($types) !== 1 || !isset($types[0]) || !is_string($types[0])
            || preg_match('/\A[ \t]*application\/json[ \t]*(?:;[ \t]*charset[ \t]*=[ \t]*(?:utf-8|"utf-8")[ \t]*)?\z/i', $types[0]) !== 1) {
            throw new TelegramWebhookRequestException(TelegramWebhookRejectionReason::UNSUPPORTED_MEDIA_TYPE);
        }

        /** @var array<array-key, mixed> $encodings */
        $encodings = $request->getHeaders()->get('Content-Encoding', [], false);
        if ($encodings !== [] && (count($encodings) !== 1 || !isset($encodings[0]) || !is_string($encodings[0])
            || preg_match('/\A[ \t]*identity[ \t]*\z/i', $encodings[0]) !== 1)) {
            throw new TelegramWebhookRequestException(TelegramWebhookRejectionReason::UNSUPPORTED_MEDIA_TYPE);
        }
    }
}
