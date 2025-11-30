<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Chaperone\CircuitBreakers;

use Cline\Chaperone\Contracts\CircuitBreaker;

use function config;

/**
 * Registry for managing multiple circuit breaker instances.
 *
 * Provides centralized management of circuit breakers, allowing retrieval
 * of circuit breaker instances by service name. Maintains a singleton
 * registry of circuit breakers to ensure consistent state management
 * across the application.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class CircuitBreakerRegistry
{
    /**
     * Cache of circuit breaker instances by service name.
     *
     * @var array<string, CircuitBreaker>
     */
    private array $breakers = [];

    /**
     * Get or create a circuit breaker for the specified service.
     *
     * Returns an existing circuit breaker instance if one exists for the
     * service, otherwise creates a new one with default configuration.
     *
     * @param string $service Service name for the circuit breaker
     *
     * @return CircuitBreaker Circuit breaker instance for the service
     */
    public function get(string $service): CircuitBreaker
    {
        if (!isset($this->breakers[$service])) {
            $this->breakers[$service] = new CircuitBreakerManager(
                service: $service,
                failureThreshold: config('chaperone.circuit_breaker.failure_threshold'),
                timeout: config('chaperone.circuit_breaker.timeout'),
                halfOpenAttempts: config('chaperone.circuit_breaker.half_open_attempts'),
            );
        }

        return $this->breakers[$service];
    }

    /**
     * Check if a circuit breaker exists for the specified service.
     *
     * @param string $service Service name to check
     *
     * @return bool True if circuit breaker exists in registry
     */
    public function has(string $service): bool
    {
        return isset($this->breakers[$service]);
    }

    /**
     * Remove a circuit breaker from the registry.
     *
     * Useful for testing or when a service is decommissioned. Does not
     * affect the database record, only removes from in-memory cache.
     *
     * @param string $service Service name to remove
     */
    public function forget(string $service): void
    {
        unset($this->breakers[$service]);
    }

    /**
     * Get all registered circuit breakers.
     *
     * @return array<string, CircuitBreaker> Array of circuit breakers by service name
     */
    public function all(): array
    {
        return $this->breakers;
    }

    /**
     * Clear all circuit breakers from the registry.
     *
     * Useful for testing or resetting application state. Does not affect
     * database records, only clears in-memory cache.
     */
    public function clear(): void
    {
        $this->breakers = [];
    }
}
