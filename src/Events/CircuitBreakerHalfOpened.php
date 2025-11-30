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
 * Event dispatched when a circuit breaker enters half-open state.
 *
 * Fired when a circuit breaker transitions from open to half-open state
 * after the configured timeout period expires. In half-open state, a limited
 * number of test requests are allowed through to determine if the service
 * has recovered.
 *
 * Used to track circuit breaker state transitions, monitor recovery attempts,
 * and log the testing phase before full service restoration. Success of these
 * test requests determines whether the circuit fully closes or reopens.
 *
 * ```php
 * Event::listen(CircuitBreakerHalfOpened::class, function ($event) {
 *     Log::info("Circuit breaker half-opened", [
 *         'service' => $event->service,
 *         'half_opened_at' => $event->halfOpenedAt->format('c'),
 *     ]);
 *
 *     // Monitor test requests closely
 *     Metrics::increment('circuit_breaker.half_open_attempts', [
 *         'service' => $event->service,
 *     ]);
 *
 *     // Log that service recovery is being tested
 *     Log::info("Testing service recovery for {$event->service}");
 * });
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 */
final readonly class CircuitBreakerHalfOpened
{
    /**
     * Create a new circuit breaker half-opened event.
     *
     * @param string            $service       Identifier for the protected service
     * @param DateTimeImmutable $halfOpenedAt  Timestamp when the circuit breaker entered half-open state
     */
    public function __construct(
        public string $service,
        public DateTimeImmutable $halfOpenedAt,
    ) {}
}
