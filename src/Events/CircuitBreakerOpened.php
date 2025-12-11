<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Chaperone\Events;

use DateTimeImmutable;

/**
 * Event dispatched when a circuit breaker opens.
 *
 * Fired when a circuit breaker transitions from closed to open state due to
 * exceeding the failure threshold. When open, all requests to the protected
 * service are immediately rejected without attempting execution.
 *
 * Used to alert on service degradation, trigger fallback mechanisms, and
 * prevent cascading failures by stopping requests to failing services.
 * Provides the service identifier and failure count that triggered opening.
 *
 * ```php
 * Event::listen(CircuitBreakerOpened::class, function ($event) {
 *     Log::critical("Circuit breaker opened", [
 *         'service' => $event->service,
 *         'failure_count' => $event->failureCount,
 *         'opened_at' => $event->openedAt->format('c'),
 *     ]);
 *
 *     // Notify operations team
 *     Alert::send("Service {$event->service} circuit breaker opened");
 *
 *     // Enable fallback mechanisms
 *     FallbackService::enable($event->service);
 * });
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 * @psalm-immutable
 */
final readonly class CircuitBreakerOpened
{
    /**
     * Create a new circuit breaker opened event.
     *
     * @param string            $service      Identifier for the protected service
     * @param int               $failureCount Number of failures that triggered the circuit to open
     * @param DateTimeImmutable $openedAt     Timestamp when the circuit breaker opened
     */
    public function __construct(
        public string $service,
        public int $failureCount,
        public DateTimeImmutable $openedAt,
    ) {}
}
