<?php

declare(strict_types=1);

namespace core\infrastructure\handler;

use core\domain\exception\IHttpException;
use core\domain\exception\ValidationException;
use Throwable;
use Yii;
use yii\web\ErrorHandler;
use yii\web\Response;
use core\application\dto\ErrorDto;
use yii\web\HttpException;

class JsonErrorHandler extends ErrorHandler
{
    protected function renderException($exception): void
    {
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
