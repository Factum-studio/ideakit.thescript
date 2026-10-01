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

    /** @param array<mixed> $routes */
    public function __construct(array $routes)
    {
        if ($routes === [] || !array_is_list($routes)) {
            throw new InvalidArgumentException('outbox_route_invalid');
        }
        $registered = [];
        foreach ($routes as $route) {
            if (!$route instanceof OutboxRoute) {
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
