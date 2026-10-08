<?php

declare(strict_types=1);

namespace modules\telegram\presentation\controller;

use modules\telegram\application\exception\InvalidTelegramUpdatePayloadException;
use modules\telegram\application\exception\TelegramUpdateAcceptanceUnavailableException;
use modules\telegram\application\port\IAcceptTelegramUpdate;
use modules\telegram\infrastructure\config\InvalidTelegramWebhookConfigException;
use modules\telegram\infrastructure\transport\http\TelegramWebhookHttpAdapter;
use modules\telegram\infrastructure\transport\http\TelegramWebhookRequest;
use modules\telegram\infrastructure\transport\http\TelegramWebhookRequestException;
use modules\telegram\infrastructure\transport\http\TelegramWebhookRejectionReason;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Throwable;
use yii\base\Module;
use yii\filters\VerbFilter;
use yii\web\BadRequestHttpException;
use yii\web\Controller;
use yii\web\Response;

final class WebhookController extends Controller
{
    public const ERROR_MESSAGES = [
        400 => 'webhook_invalid_request', 403 => 'webhook_forbidden', 405 => 'webhook_method_not_allowed',
        413 => 'webhook_payload_too_large', 415 => 'webhook_unsupported_media_type',
        500 => 'webhook_internal_error', 503 => 'webhook_unavailable',
    ];

    /** @param array<string, mixed> $config */
    public function __construct(
        string $id,
        Module $module,
        private readonly TelegramWebhookHttpAdapter $adapter,
        private readonly IAcceptTelegramUpdate $acceptor,
        private readonly LoggerInterface $logger,
        array $config = [],
    ) {
        parent::__construct($id, $module, $config);
    }

    public function behaviors(): array
    {
        return ['verbs' => ['class' => VerbFilter::class, 'actions' => ['receive' => ['POST']]]];
    }

    public function beforeAction($action): bool
    {
        if ($action->id === 'receive') {
            if (!$this->request instanceof TelegramWebhookRequest || !$this->request->isCanonicalWebhookRequest()) {
                throw new BadRequestHttpException('webhook_invalid_request');
            }
            $this->enableCsrfValidation = false;
        }

        return parent::beforeAction($action);
    }

    public function actionReceive(): Response
    {
        try {
            if (!$this->request instanceof TelegramWebhookRequest) {
                return $this->failure(500);
            }
            $command = $this->adapter->toCommand($this->request);
            $this->acceptor->handle($command);
        } catch (TelegramWebhookRequestException $exception) {
            return match ($exception->reason) {
                TelegramWebhookRejectionReason::INVALID_REQUEST => $this->failure(400),
                TelegramWebhookRejectionReason::FORBIDDEN => $this->failure(403),
                TelegramWebhookRejectionReason::UNSUPPORTED_MEDIA_TYPE => $this->failure(415),
                TelegramWebhookRejectionReason::PAYLOAD_TOO_LARGE => $this->failure(413),
                TelegramWebhookRejectionReason::INVALID_UPDATE => $this->failure(400, 'webhook_invalid_update'),
                TelegramWebhookRejectionReason::INTERNAL_FAILURE => $this->failure(500),
            };
        } catch (InvalidTelegramUpdatePayloadException) {
            return $this->failure(400, 'webhook_invalid_update');
        } catch (InvalidTelegramWebhookConfigException | TelegramUpdateAcceptanceUnavailableException) {
            return $this->failure(503);
        } catch (Throwable) {
            return $this->failure(500);
        }

        $this->response->format = Response::FORMAT_JSON;
        $this->response->statusCode = 200;
        $this->response->data = ['ok' => true];

        return $this->response;
    }

    private function failure(int $status, ?string $reason = null): Response
    {
        $reason ??= self::ERROR_MESSAGES[$status];
        $this->logger->log($status >= 500 ? 'error' : 'warning', 'sensitive_request.failed', [
            'reason' => $reason, 'http_status' => $status, 'correlation_id' => Uuid::uuid4()->toString(),
        ]);
        $this->response->format = Response::FORMAT_JSON;
        $this->response->statusCode = $status;
        $this->response->data = ['error' => ['code' => $status, 'message' => $reason]];

        return $this->response;
    }
}
