<?php

declare(strict_types=1);

namespace modules\telegram\infrastructure\transport\http;

use modules\telegram\application\command\AcceptTelegramUpdateCommand;
use Throwable;
use yii\web\Request;

class TelegramWebhookRequest extends Request
{
    private ?string $webhookBody = null;

    public function isCanonicalWebhookRequest(): bool
    {
        return ($_SERVER['REQUEST_URI'] ?? null) === '/telegram/webhook'
            && ($_SERVER['QUERY_STRING'] ?? '') === '';
    }

    public function getMethod(): string
    {
        if (!$this->isWebhookPath()) {
            return parent::getMethod();
        }

        $method = $_SERVER['REQUEST_METHOD'] ?? '';
        return is_string($method) ? $method : '';
    }

    /**
     * @param mixed $rawBody
     * @throws TelegramWebhookRequestException
     */
    public function setRawBody(mixed $rawBody): void
    {
        if ($rawBody !== null && !is_string($rawBody) && $this->isWebhookPath()) {
            throw new TelegramWebhookRequestException(TelegramWebhookRejectionReason::INTERNAL_FAILURE);
        }
        $this->webhookBody = is_string($rawBody) ? $rawBody : null;
        parent::setRawBody($rawBody);
    }

    /** @throws TelegramWebhookRequestException */
    public function getRawBody(): string
    {
        if (!$this->isWebhookPath()) {
            return parent::getRawBody();
        }

        $this->validateContentLength();
        if ($this->webhookBody === null) {
            $this->webhookBody = $this->readBody();
        }
        if (strlen($this->webhookBody) > AcceptTelegramUpdateCommand::MAX_PAYLOAD_BYTES) {
            throw new TelegramWebhookRequestException(TelegramWebhookRejectionReason::PAYLOAD_TOO_LARGE);
        }

        return $this->webhookBody;
    }

    /** @return resource|false */
    protected function openBodyStream()
    {
        return fopen('php://input', 'rb');
    }

    private function isWebhookPath(): bool
    {
        $uri = $_SERVER['REQUEST_URI'] ?? null;
        return is_string($uri) && explode('?', $uri, 2)[0] === '/telegram/webhook';
    }

    private function validateContentLength(): void
    {
        /** @var array<array-key, mixed> $values */
        $values = $this->getHeaders()->get('Content-Length', [], false);
        if ($values === []) {
            return;
        }
        if (count($values) !== 1 || !isset($values[0]) || !is_string($values[0])
            || preg_match('/\A[0-9]+\z/', $values[0]) !== 1) {
            throw new TelegramWebhookRequestException(TelegramWebhookRejectionReason::INVALID_REQUEST);
        }

        $length = ltrim($values[0], '0');
        $maximum = (string) AcceptTelegramUpdateCommand::MAX_PAYLOAD_BYTES;
        if (strlen($length) > strlen($maximum)
            || (strlen($length) === strlen($maximum) && strcmp($length, $maximum) > 0)) {
            throw new TelegramWebhookRequestException(TelegramWebhookRejectionReason::PAYLOAD_TOO_LARGE);
        }
    }

    private function readBody(): string
    {
        try {
            $stream = $this->openBodyStream();
            if ($stream === false) {
                throw new TelegramWebhookRequestException(TelegramWebhookRejectionReason::INTERNAL_FAILURE);
            }

            try {
                $body = '';
                $remaining = AcceptTelegramUpdateCommand::MAX_PAYLOAD_BYTES + 1;
                while ($remaining > 0 && !feof($stream)) {
                    $size = min(8192, $remaining);
                    // Prevent stream buffering from reading beyond the final one-byte probe.
                    stream_set_chunk_size($stream, $size);
                    $chunk = fread($stream, $size);
                    if ($chunk === false || ($chunk === '' && !feof($stream))) {
                        throw new TelegramWebhookRequestException(TelegramWebhookRejectionReason::INTERNAL_FAILURE);
                    }
                    $body .= $chunk;
                    $remaining -= strlen($chunk);
                }

                return $body;
            } finally {
                fclose($stream);
            }
        } catch (TelegramWebhookRequestException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new TelegramWebhookRequestException(TelegramWebhookRejectionReason::INTERNAL_FAILURE, $exception);
        }
    }
}
