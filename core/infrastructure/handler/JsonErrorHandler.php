<?php

declare(strict_types=1);

namespace core\infrastructure\handler;

use yii\web\ErrorHandler;
use yii\web\Response;
use core\application\dto\ErrorDto;
use yii\web\HttpException;

class JsonErrorHandler extends ErrorHandler
{
    protected function renderException($exception)
    {
        $response = \Yii::$app->response ?? new Response();

        $response->format = Response::FORMAT_JSON;

        $statusCode = 500;

        if ($exception instanceof HttpException) {
            $statusCode = $exception->statusCode;
        }
        $message = $exception->getMessage() ?: 'Internal Server Error';

        $details = method_exists($exception, 'getDetails')
            ? $exception->getDetails()
            : [];

        if (YII_DEBUG) {
            $_ENV['DEBUG_LVL'] == (1 | 2 | 3) ? $details['trace'] = $this->getTraceAsArray($exception) : null;
            $_ENV['DEBUG_LVL'] == (2 | 3) ? $details['request'] = [
                'method'    => \Yii::$app->request->method,
                'url'       => \Yii::$app->request->url,
                'headers'   => \Yii::$app->request->headers->toArray(),
                'body'      => \Yii::$app->request->rawBody,
            ] : null;
        }

        \Yii::error($exception, 'api');

        $response->statusCode = $statusCode;
        $response->data = new ErrorDto($message, $statusCode, $details);

        $response->send();
    }

    private function getTraceAsArray($exception): array
    {
        $trace = [];

        foreach ($exception->getTrace() as $index => $item) {
            $traceItem = [
                'file'      => $item['file'] ?? 'unknown',
                'line'      => $item['line'] ?? 0,
                'function'  => $item['function'] ?? 'unknown',
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
