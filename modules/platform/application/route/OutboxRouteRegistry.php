<?php

declare(strict_types=1);

namespace modules\platform\application\route;

use InvalidArgumentException;
use modules\platform\application\dto\OutboxWriteIntent;
use modules\platform\application\enum\OutboxWriteFailure;
use modules\platform\application\exception\OutboxWriteException;

final class OutboxRouteRegistry
{
    /** @var array<string, OutboxRoute> */
    private readonly array $routes;

    /**
     * @param array<mixed> $routes
     * @param array<mixed> $supportedRoutingKeys
     */
    public function __construct(array $routes, array $supportedRoutingKeys)
    {
        if ($routes === [] || !array_is_list($routes)
            || $supportedRoutingKeys === [] || !array_is_list($supportedRoutingKeys)
        ) {
            throw new InvalidArgumentException('outbox_route_invalid');
        }
        $supported = [];
        foreach ($supportedRoutingKeys as $routingKey) {
            if (!is_string($routingKey) || $routingKey === '' || strlen($routingKey) > 64
                || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/D', $routingKey) !== 1
                || isset($supported[$routingKey])
            ) {
                throw new InvalidArgumentException('outbox_route_invalid');
            }
            $supported[$routingKey] = true;
        }
        $registered = [];
        foreach ($routes as $route) {
            if (!$route instanceof OutboxRoute || !isset($supported[$route->routingKey])) {
                throw new InvalidArgumentException('outbox_route_invalid');
            }
            $key = self::key($route->messageType, $route->schemaVersion);
            if (isset($registered[$key])) {
                throw new InvalidArgumentException('outbox_route_invalid');
            }
            $registered[$key] = $route;
        }
        $this->routes = $registered;
    }

    /** @throws OutboxWriteException */
    public function resolve(OutboxWriteIntent $intent): OutboxRoute
    {
        $route = $this->find($intent->messageType, $intent->schemaVersion);
        if (
            $route === null
            || $intent->ownerModule !== $route->ownerModule
            || $intent->aggregateType !== $route->aggregateType
            || !$route->payloadCodec->accepts($intent->payload)
        ) {
            throw new OutboxWriteException(OutboxWriteFailure::UNSUPPORTED_ROUTE);
        }

        if ($intent->aggregateId !== $route->payloadCodec->aggregateId($intent->payload)) {
            throw new OutboxWriteException(OutboxWriteFailure::INVALID_INTENT);
        }

        return $route;
    }

    public function find(string $messageType, string $schemaVersion): ?OutboxRoute
    {
        return $this->routes[self::key($messageType, $schemaVersion)] ?? null;
    }

    /** @return list<OutboxRoute> */
    public function forRoutingKey(string $routingKey): array
    {
        return array_values(array_filter($this->routes, static fn (OutboxRoute $route): bool => $route->routingKey === $routingKey));
    }

    private static function key(string $messageType, string $schemaVersion): string
    {
        return $messageType . "\0" . $schemaVersion;
    }
}
