<?php

declare(strict_types=1);

namespace modules\platform\application\route;

use modules\platform\application\dto\BackgroundCommandRegistration;
use modules\platform\application\enum\CriticalWorkerError;
use modules\platform\application\exception\CriticalWorkerException;
use modules\platform\application\port\IBackgroundCommandHandler;

final class BackgroundCommandRegistry
{
    /** @var array<string, IBackgroundCommandHandler> */
    private readonly array $handlers;

    /**
     * @param list<BackgroundCommandRegistration> $registrations
     * @throws CriticalWorkerException
     */
    public function __construct(array $registrations, private readonly OutboxRouteRegistry $routes)
    {
        $handlers = [];
        foreach ($registrations as $registration) {
            // Registration validation excludes the separator from both fields.
            $key = $registration->messageType . "\0" . $registration->schemaVersion;
            if (isset($handlers[$key])) {
                throw new CriticalWorkerException(CriticalWorkerError::CONFIGURATION_INVALID);
            }
            $handlers[$key] = $registration->handler;
        }
        $this->handlers = $handlers;
    }

    /** @throws CriticalWorkerException */
    public function requireHandler(string $messageType, string $schemaVersion): IBackgroundCommandHandler
    {
        return $this->handlers[$messageType . "\0" . $schemaVersion]
            ?? throw new CriticalWorkerException(CriticalWorkerError::UNSUPPORTED_CONTRACT);
    }

    /** @throws CriticalWorkerException */
    public function assertCriticalRouteRegistered(): void
    {
        foreach ($this->routes->forRoutingKey('critical') as $route) {
            if (isset($this->handlers[$route->messageType . "\0" . $route->schemaVersion])) {
                return;
            }
        }
        throw new CriticalWorkerException(CriticalWorkerError::HANDLER_MISSING);
    }
}
