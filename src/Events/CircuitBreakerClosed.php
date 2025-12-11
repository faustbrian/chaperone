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
 * Event dispatched when a circuit breaker closes.
 *
 * Fired when a circuit breaker transitions from half-open to closed state
 * after successful test requests indicate the protected service has recovered.
 * When closed, all requests are allowed through and failures are monitored.
 *
 * Used to track service recovery, disable fallback mechanisms, and log
 * successful circuit breaker cycles. Indicates the service is healthy and
 * normal operation has resumed.
 *
 * ```php
 * Event::listen(CircuitBreakerClosed::class, function ($event) {
 *     Log::info("Circuit breaker closed", [
 *         'service' => $event->service,
 *         'closed_at' => $event->closedAt->format('c'),
 *     ]);
 *
 *     // Notify that service has recovered
 *     Alert::resolve("Service {$event->service} circuit breaker closed - service recovered");
 *
 *     // Disable fallback mechanisms
 *     FallbackService::disable($event->service);
 *
 *     // Track recovery metrics
 *     Metrics::increment('circuit_breaker.recoveries', ['service' => $event->service]);
 * });
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 * @psalm-immutable
 */
final readonly class CircuitBreakerClosed
{
    /**
     * Create a new circuit breaker closed event.
     *
     * @param string            $service  Identifier for the protected service
     * @param DateTimeImmutable $closedAt Timestamp when the circuit breaker closed
     */
    public function __construct(
        public string $service,
        public DateTimeImmutable $closedAt,
    ) {}
}
