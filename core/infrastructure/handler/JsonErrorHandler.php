<?php

declare(strict_types=1);

namespace core\infrastructure\handler;

use core\domain\exception\IHttpException;
use core\domain\exception\ValidationException;
use core\infrastructure\logging\YiiSensitiveRequestLogger;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Throwable;
use Yii;
use yii\BaseYii;
use yii\web\ErrorHandler;
use yii\web\Response;
use core\application\dto\ErrorDto;
use yii\web\HttpException;

class JsonErrorHandler extends ErrorHandler
{
    /** @var list<string> */
    public array $sensitivePaths = [];
    /** @var array<int, string> */
    public array $sensitiveErrorMessages = [500 => 'internal_error'];
    public ?LoggerInterface $sensitiveLogger = null;

    public function logException($exception): void
    {
        if (!$this->isSensitivePath()) {
            parent::logException($exception);

            return;
        }

        // Buffered diagnostics can add request globals during Yii's final flush.
        // Only the allowlisted boundary record is retained for sensitive failures.
        $dispatcher = Yii::$app->getLog();
        BaseYii::getLogger()->messages = [];
        foreach ($dispatcher->targets as $target) {
            $target->messages = [];
        }
        $status = $this->sensitiveStatus($exception);
        $logger = $this->sensitiveLogger ?? new YiiSensitiveRequestLogger(
            $dispatcher,
            array_values($this->sensitiveErrorMessages),
        );
        $logger->log($status >= 500 ? 'error' : 'warning', 'sensitive_request.failed', [
            'reason' => $this->sensitiveMessage($status),
            'http_status' => $status,
            'correlation_id' => Uuid::uuid4()->toString(),
        ]);
    }

    protected function renderException($exception): void
    {
        if ($this->isSensitivePath()) {
            $response = Yii::$app->getResponse();
            $response->clear();
            $response->format = Response::FORMAT_JSON;
            $status = $this->sensitiveStatus($exception);
            $response->setStatusCode($status);
            if ($status === 405) {
                $response->getHeaders()->set('Allow', 'POST');
            }
            $response->data = ['error' => ['code' => $status, 'message' => $this->sensitiveMessage($status)]];
            if (($_SERVER['REQUEST_METHOD'] ?? null) === 'HEAD') {
                $response->format = Response::FORMAT_RAW;
                $response->getHeaders()->set('Content-Type', 'application/json; charset=UTF-8');
                $response->data = null;
                $response->content = '';
            }
            // A failed formatter must not leave partially rendered sensitive output.
            ob_start();
            try {
                $response->send();
            } catch (Throwable $failure) {
                ob_end_clean();
                throw $failure;
            }
            ob_end_flush();

            return;
        }

        $response = Yii::$app->response ?? new Response();

        $response->format = Response::FORMAT_JSON;

        if ($exception instanceof HttpException) {
            $statusCode = $exception->statusCode;
        } elseif ($exception instanceof IHttpException) {
            $statusCode = $exception->getStatusCode();
        } else {
            $statusCode = 500;
        }

        $message = $exception->getMessage() ?: 'Internal Server Error';

        $details = [];
        if ($exception instanceof ValidationException) {
            $details['errors'] = $exception->getErrors();
        }
        if (method_exists($exception, 'getDetails')) {
            $details = array_merge($details, $exception->getDetails());
        }

        // @phpstan-ignore-next-line
        if (YII_DEBUG) {
            if (in_array($_ENV['DEBUG_LVL'] ?? 0, [1, 2, 3])) {
                $details['trace'] = $this->getTraceAsArray($exception);
            }
            if (($_ENV['DEBUG_LVL'] ?? 0) == 2 || ($_ENV['DEBUG_LVL'] ?? 0) == 3) {
                $details['request'] = [
                    'method'  => Yii::$app->request->method,
                    'url'     => Yii::$app->request->url,
                    'headers' => Yii::$app->request->headers->toArray(),
                    'body'    => Yii::$app->request->rawBody,
                ];
            }
        }

        Yii::error($exception, 'api');

        $response->statusCode = $statusCode;
        $response->data = new ErrorDto($message, $statusCode, $details);

        $response->send();
    }

    protected function handleFallbackExceptionMessage($exception, $previousException): void
    {
        if (!$this->isSensitivePath()) {
            parent::handleFallbackExceptionMessage($exception, $previousException);

            return;
        }

        // Avoid the failed response formatter and Yii's verbose fallback logger.
        $this->clearOutput();
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=UTF-8');
        }
        if (($_SERVER['REQUEST_METHOD'] ?? null) !== 'HEAD') {
            echo json_encode(['error' => ['code' => 500, 'message' => $this->sensitiveMessage(500)]], JSON_THROW_ON_ERROR);
        }
        error_log('sensitive_request.fallback');
        exit(1);
    }

    private function isSensitivePath(): bool
    {
        $uri = $_SERVER['REQUEST_URI'] ?? null;

        return is_string($uri) && in_array(explode('?', $uri, 2)[0], $this->sensitivePaths, true);
    }

    private function sensitiveStatus(Throwable $exception): int
    {
        $status = $exception instanceof HttpException ? $exception->statusCode : 500;

        return in_array($status, [400, 403, 405, 413, 415, 500, 503], true)
            && isset($this->sensitiveErrorMessages[$status]) ? $status : 500;
    }

    private function sensitiveMessage(int $status): string
    {
        $message = $this->sensitiveErrorMessages[$status] ?? 'internal_error';

        return preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $message) === 1 ? $message : 'internal_error';
    }

    private function getTraceAsArray(Throwable $exception): array
    {
        $trace = [];

        foreach ($exception->getTrace() as $index => $item) {
            $traceItem = [
                'file'      => $item['file'] ?? 'unknown',
                'line'      => $item['line'] ?? 0,
                'function'  => $item['function'],
                'class'     => $item['class'] ?? '',
                'type'      => $item['type'] ?? '',
            ];

            if ($_ENV['DEBUG_LVL'] == 3 && !empty($item['args'])) {
                $traceItem['args'] = $this->formatArgs($item['args']);
            }

            $trace[] = $traceItem;
        }

        return $trace;
    }

    /**
     * @param array<mixed> $args
     */
    private function formatArgs(array $args): array
    {
        $formatted = [];
        foreach ($args as $arg) {
            if (is_object($arg)) {
                $formatted[] = 'Object(' . get_class($arg) . ')';
            } elseif (is_array($arg)) {
                $formatted[] = 'Array(' . count($arg) . ')';
            } elseif (is_string($arg)) {
                $formatted[] = '"' . (strlen($arg) > 50 ? substr($arg, 0, 50) . '...' : $arg) . '"';
            } elseif (is_null($arg)) {
                $formatted[] = 'null';
            } elseif (is_bool($arg)) {
                $formatted[] = $arg ? 'true' : 'false';
            } else {
                $formatted[] = (string)$arg;
            }
        }
        return $formatted;
    }
}
