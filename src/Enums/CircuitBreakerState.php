<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Chaperone\Enums;

/**
 * Defines circuit breaker states for job supervision.
 *
 * Implements the circuit breaker pattern to prevent cascading failures by
 * temporarily stopping execution of consistently failing jobs. The circuit
 * transitions between states based on success/failure rates and timeout periods.
 *
 * State transitions:
 * - Closed -> Open: After failure_threshold consecutive failures
 * - Open -> HalfOpen: After timeout period expires
 * - HalfOpen -> Closed: After success_threshold consecutive successes
 * - HalfOpen -> Open: On any failure during half-open testing
 *
 * ```php
 * // Check if circuit allows execution
 * if ($circuitBreaker->state() === CircuitBreakerState::Closed) {
 *     // Execute job
 * }
 *
 * // Transition states
 * $circuitBreaker->recordFailure();
 * if ($circuitBreaker->failureCount >= $threshold) {
 *     $circuitBreaker->trip(); // Closed -> Open
 * }
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 */
enum CircuitBreakerState: string
{
    /**
     * Circuit is operating normally and allowing all job executions.
     *
     * In this state, jobs execute normally and failures are tracked. When
     * consecutive failures reach the failure_threshold, the circuit trips
     * to Open state. This is the ideal state representing healthy job execution.
     */
    case Closed = 'closed';

    /**
     * Circuit is open and blocking all job executions.
     *
     * Jobs are prevented from executing to stop cascading failures and give
     * dependent systems time to recover. The circuit remains open for a timeout
     * period before transitioning to HalfOpen for test executions. Failed jobs
     * during this state are immediately rejected without execution.
     */
    case Open = 'open';

    /**
     * Circuit is testing recovery with limited job executions.
     *
     * A limited number of jobs are allowed to execute to test if the underlying
     * issue has been resolved. If success_threshold consecutive successes occur,
     * the circuit closes. Any failure immediately reopens the circuit. This state
     * provides a controlled recovery mechanism.
     */
    case HalfOpen = 'half_open';

    /**
     * Determine if the circuit allows job execution.
     *
     * Only Closed and HalfOpen states permit execution, though HalfOpen
     * limits the number of concurrent attempts.
     *
     * @return bool True if jobs can execute
     */
    public function allowsExecution(): bool
    {
        return $this !== self::Open;
    }

    /**
     * Determine if the circuit is open and blocking execution.
     *
     * @return bool True if the circuit is open
     */
    public function isOpen(): bool
    {
        return $this === self::Open;
    }

    /**
     * Determine if the circuit is closed and healthy.
     *
     * @return bool True if the circuit is closed
     */
    public function isClosed(): bool
    {
        return $this === self::Closed;
    }

    /**
     * Determine if the circuit is in recovery testing mode.
     *
     * @return bool True if the circuit is half-open
     */
    public function isHalfOpen(): bool
    {
        return $this === self::HalfOpen;
    }

    /**
     * Get a human-readable label for this state.
     *
     * @return string The display label
     */
    public function label(): string
    {
        return match ($this) {
            self::Closed => 'Closed',
            self::Open => 'Open',
            self::HalfOpen => 'Half Open',
        };
    }

    /**
     * Get a color code for UI representation.
     *
     * @return string Color name suitable for UI frameworks
     */
    public function color(): string
    {
        return match ($this) {
            self::Closed => 'green',
            self::Open => 'red',
            self::HalfOpen => 'yellow',
        };
    }

    /**
     * Get a description of what this state means.
     *
     * @return string Human-readable description
     */
    public function description(): string
    {
        return match ($this) {
            self::Closed => 'Operating normally, all jobs allowed',
            self::Open => 'Blocking execution due to failures',
            self::HalfOpen => 'Testing recovery with limited execution',
        };
    }
}
