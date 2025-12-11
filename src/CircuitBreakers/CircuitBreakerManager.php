<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Chaperone\CircuitBreakers;

use Cline\Chaperone\Contracts\CircuitBreaker as CircuitBreakerContract;
use Cline\Chaperone\Database\Models\CircuitBreaker as CircuitBreakerModel;
use Cline\Chaperone\Enums\CircuitBreakerState;
use Cline\Chaperone\Events\CircuitBreakerClosed;
use Cline\Chaperone\Events\CircuitBreakerHalfOpened;
use Cline\Chaperone\Events\CircuitBreakerOpened;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Throwable;

use function config;
use function sprintf;

/**
 * Circuit breaker implementation for protecting against cascading failures.
 *
 * Implements the circuit breaker pattern to monitor service health and prevent
 * cascading failures by opening the circuit when failure thresholds are reached.
 * Automatically transitions through states (Closed -> Open -> HalfOpen -> Closed)
 * based on success/failure counts and configured timeouts.
 *
 * Thread-safe through cache locks for concurrent access across processes.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class CircuitBreakerManager implements CircuitBreakerContract
{
    /**
     * Circuit breaker database record.
     */
    private CircuitBreakerModel $model;

    /**
     * Number of failures before opening the circuit.
     */
    private readonly int $failureThreshold;

    /**
     * Seconds to wait before transitioning from Open to HalfOpen.
     */
    private readonly int $timeout;

    /**
     * Number of successful requests required in HalfOpen state to close the circuit.
     */
    private readonly int $halfOpenAttempts;

    /**
     * Create a new circuit breaker manager instance.
     *
     * @param string $service          Service name for this circuit breaker
     * @param int    $failureThreshold Number of failures before opening circuit
     * @param int    $timeout          Seconds before transitioning to half-open
     * @param int    $halfOpenAttempts Successful attempts needed to close circuit
     */
    public function __construct(
        private readonly string $service,
        ?int $failureThreshold = null,
        ?int $timeout = null,
        ?int $halfOpenAttempts = null,
    ) {
        $this->failureThreshold = $failureThreshold ?? config('chaperone.circuit_breaker.failure_threshold', 5);
        $this->timeout = $timeout ?? config('chaperone.circuit_breaker.timeout', 60);
        $this->halfOpenAttempts = $halfOpenAttempts ?? config('chaperone.circuit_breaker.half_open_attempts', 3);

        $this->loadOrCreateModel();
    }

    /**
     * Execute a callback with circuit breaker protection.
     *
     * Checks circuit state before execution and records success/failure.
     * Opens circuit if failure threshold is reached, closes circuit if
     * half-open attempts succeed.
     *
     * @param callable $callback Operation to protect with circuit breaker
     *
     * @throws RuntimeException When circuit is open
     * @throws Throwable        When operation fails
     *
     * @return mixed Result of the callback execution
     */
    public function call(callable $callback): mixed
    {
        // Transition to half-open if timeout has elapsed
        if ($this->shouldTransitionToHalfOpen()) {
            $this->transitionToHalfOpen();
        }

        if ($this->isOpen()) {
            throw new RuntimeException(
                sprintf('Circuit breaker for service [%s] is open', $this->service),
            );
        }

        try {
            $result = $callback();
            $this->recordSuccess();

            return $result;
        } catch (Throwable $throwable) {
            $this->recordFailure($throwable);

            throw $throwable;
        }
    }

    /**
     * Check if the circuit is in Open state.
     *
     * @return bool True if circuit is open and blocking requests
     */
    public function isOpen(): bool
    {
        $this->refreshModel();

        return $this->model->state === CircuitBreakerState::Open;
    }

    /**
     * Check if the circuit is in HalfOpen state.
     *
     * @return bool True if circuit is testing recovery
     */
    public function isHalfOpen(): bool
    {
        $this->refreshModel();

        return $this->model->state === CircuitBreakerState::HalfOpen;
    }

    /**
     * Check if the circuit is in Closed state.
     *
     * @return bool True if circuit is allowing requests normally
     */
    public function isClosed(): bool
    {
        $this->refreshModel();

        return $this->model->state === CircuitBreakerState::Closed;
    }

    /**
     * Manually open the circuit.
     *
     * Forces the circuit into Open state, blocking all requests.
     * Useful for manual intervention or testing.
     */
    public function open(): void
    {
        $this->withLock(function (): void {
            $this->doOpen();
        });
    }

    /**
     * Manually close the circuit.
     *
     * Forces the circuit into Closed state, allowing requests through.
     * Resets failure and success counts. Useful for manual intervention.
     */
    public function close(): void
    {
        $this->withLock(function (): void {
            $this->doClose();
        });
    }

    /**
     * Record a successful request.
     *
     * In HalfOpen state, increments success count and closes circuit if
     * half-open attempts threshold is reached. In Closed state, resets
     * failure count to zero.
     */
    public function recordSuccess(): void
    {
        $this->withLock(function (): void {
            $this->refreshModel();

            if ($this->model->state === CircuitBreakerState::HalfOpen) {
                $successCount = $this->model->success_count + 1;

                if ($successCount >= $this->halfOpenAttempts) {
                    $this->doClose();
                } else {
                    $this->model->update([
                        'success_count' => $successCount,
                        'failure_count' => 0,
                    ]);
                }
            } elseif ($this->model->state === CircuitBreakerState::Closed) {
                $this->model->update([
                    'failure_count' => 0,
                ]);
            }
        });
    }

    /**
     * Record a failed request.
     *
     * Increments failure count and opens circuit if failure threshold
     * is reached. In HalfOpen state, immediately reopens the circuit.
     *
     * @param Throwable $exception Exception that caused the failure
     */
    public function recordFailure(Throwable $exception): void
    {
        $this->withLock(function (): void {
            $this->refreshModel();
            $failureCount = $this->model->failure_count + 1;
            $this->model->update([
                'failure_count' => $failureCount,
                'success_count' => 0,
                'last_failure_at' => Date::now(),
            ]);

            // In half-open state, any failure reopens the circuit
            if ($this->model->state === CircuitBreakerState::HalfOpen) {
                $this->doOpen();

                return;
            }

            // In closed state, open if threshold is reached
            if ($this->model->state !== CircuitBreakerState::Closed || $failureCount < $this->failureThreshold) {
                return;
            }

            $this->doOpen();
        });
    }

    /**
     * Open the circuit without acquiring a lock.
     *
     * Internal method for use within existing locks to prevent deadlock.
     */
    private function doOpen(): void
    {
        $this->model->update([
            'state' => CircuitBreakerState::Open,
            'opened_at' => Date::now(),
        ]);

        Event::dispatch(
            new CircuitBreakerOpened(
                $this->service,
                $this->model->failure_count,
                Date::now()->toDateTimeImmutable(),
            )
        );
    }

    /**
     * Close the circuit without acquiring a lock.
     *
     * Internal method for use within existing locks to prevent deadlock.
     */
    private function doClose(): void
    {
        $this->model->update([
            'state' => CircuitBreakerState::Closed,
            'failure_count' => 0,
            'success_count' => 0,
            'opened_at' => null,
        ]);

        Event::dispatch(
            new CircuitBreakerClosed(
                $this->service,
                Date::now()->toDateTimeImmutable(),
            )
        );
    }

    /**
     * Check if circuit should transition from Open to HalfOpen.
     *
     * Returns true if circuit is open and the timeout period has elapsed.
     *
     * @return bool True if ready to transition to half-open state
     */
    private function shouldTransitionToHalfOpen(): bool
    {
        if ($this->model->state !== CircuitBreakerState::Open) {
            return false;
        }

        if ($this->model->opened_at === null) {
            return false;
        }

        return $this->model->opened_at->addSeconds($this->timeout)->isPast();
    }

    /**
     * Transition circuit to HalfOpen state.
     *
     * Moves circuit from Open to HalfOpen state to test if service has recovered.
     */
    private function transitionToHalfOpen(): void
    {
        $this->withLock(function (): void {
            $this->model->update([
                'state' => CircuitBreakerState::HalfOpen,
                'success_count' => 0,
                'failure_count' => 0,
            ]);

            Event::dispatch(
                new CircuitBreakerHalfOpened(
                    $this->service,
                    Date::now()->toDateTimeImmutable(),
                )
            );
        });
    }

    /**
     * Load existing circuit breaker model or create a new one.
     *
     * Retrieves the database record for this service's circuit breaker,
     * or creates a new one in Closed state if it doesn't exist.
     */
    private function loadOrCreateModel(): void
    {
        $this->model = CircuitBreakerModel::query()->firstOrCreate(
            ['service_name' => $this->service],
            [
                'state' => CircuitBreakerState::Closed,
                'failure_count' => 0,
            ],
        );
    }

    /**
     * Refresh the model from the database.
     *
     * Ensures we have the latest state from the database, important for
     * concurrent access scenarios.
     */
    private function refreshModel(): void
    {
        $this->model->refresh();
    }

    /**
     * Execute a callback within a cache lock for thread safety.
     *
     * Acquires a cache lock before executing the callback to ensure
     * atomic operations across multiple processes or servers.
     *
     * @param callable $callback Operation to execute within lock
     */
    private function withLock(callable $callback): void
    {
        $lock = Cache::lock(sprintf('circuit-breaker:%s', $this->service), 10);

        try {
            $lock->block(5);
            $callback();
        } finally {
            $lock->release();
        }
    }
}
