<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Chaperone;

use Cline\Chaperone\CircuitBreakers\CircuitBreakerRegistry;
use Cline\Chaperone\Contracts\CircuitBreaker;
use Cline\Chaperone\Contracts\HealthMonitor;
use Cline\Chaperone\Contracts\ResourceMonitor;
use Cline\Chaperone\Deployment\DeploymentCoordinator;
use Cline\Chaperone\Supervisors\HeartbeatMonitor;
use Cline\Chaperone\Supervisors\JobSupervisor;
use Cline\Chaperone\WorkerPools\WorkerPoolRegistry;
use Cline\Chaperone\WorkerPools\WorkerPoolSupervisor;

/**
 * Main orchestrator for Chaperone job supervision and monitoring.
 *
 * Provides a fluent, high-level API for supervising Laravel jobs, managing circuit
 * breakers, tracking heartbeats, and monitoring job health. Serves as the primary
 * entry point for all Chaperone functionality, coordinating between supervision
 * strategies, circuit breakers, health monitors, and resource enforcement.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final readonly class Chaperone
{
    /**
     * Create a new Chaperone manager instance.
     *
     * @param CircuitBreakerRegistry $circuitBreakerRegistry Registry for managing circuit breakers
     * @param HeartbeatMonitor       $heartbeatMonitor       Service for tracking job heartbeats
     * @param HealthMonitor          $healthMonitor          Service for monitoring job health
     * @param ResourceMonitor        $resourceMonitor        Service for enforcing resource limits
     * @param WorkerPoolRegistry     $workerPoolRegistry     Registry for managing worker pools
     */
    public function __construct(
        private CircuitBreakerRegistry $circuitBreakerRegistry,
        private HeartbeatMonitor $heartbeatMonitor,
        private HealthMonitor $healthMonitor,
        private ResourceMonitor $resourceMonitor,
        private WorkerPoolRegistry $workerPoolRegistry,
    ) {}

    /**
     * Start supervising a job with comprehensive monitoring.
     *
     * Creates a new JobSupervisor instance configured to monitor the specified
     * job class. The supervisor provides a fluent interface for configuring
     * resource limits, timeouts, heartbeat intervals, and callbacks for
     * supervision events.
     *
     * Example:
     * ```php
     * Chaperone::watch(ProcessPaymentJob::class)
     *     ->withTimeout(300)
     *     ->withMemoryLimit(512)
     *     ->onTimeout(fn($id) => Log::error("Job {$id} timed out"))
     *     ->supervise(ProcessPaymentJob::class);
     * ```
     *
     * @return JobSupervisor Supervisor instance for fluent configuration
     */
    public function watch(): JobSupervisor
    {
        return new JobSupervisor(
            $this->resourceMonitor,
            $this->healthMonitor,
            $this->heartbeatMonitor,
        );
    }

    /**
     * Get or create a circuit breaker for the specified service.
     *
     * Returns a circuit breaker instance that can be used to protect external
     * service calls from cascading failures. The circuit breaker automatically
     * tracks success/failure rates and opens when failure thresholds are reached.
     *
     * Example:
     * ```php
     * $breaker = Chaperone::circuitBreaker('payment-api');
     *
     * $result = $breaker->call(function() {
     *     return PaymentGateway::charge($amount);
     * });
     * ```
     *
     * @param  string         $service Service name for the circuit breaker
     * @return CircuitBreaker Circuit breaker instance for the service
     */
    public function circuitBreaker(string $service): CircuitBreaker
    {
        return $this->circuitBreakerRegistry->get($service);
    }

    /**
     * Record a heartbeat for a supervised job.
     *
     * Updates the last heartbeat timestamp for the supervision session,
     * indicating the job is still actively running. Metadata can include
     * progress information, current state, or any contextual data useful
     * for monitoring and debugging.
     *
     * Example:
     * ```php
     * Chaperone::heartbeat($supervisionId, [
     *     'progress' => 45,
     *     'current_step' => 'processing_batch_3',
     *     'items_processed' => 1500,
     * ]);
     * ```
     *
     * @param string $supervisionId Unique identifier for the supervision session
     * @param array  $metadata      Contextual information about job progress
     */
    public function heartbeat(string $supervisionId, array $metadata = []): void
    {
        $this->heartbeatMonitor->recordHeartbeat($supervisionId, $metadata);
    }

    /**
     * Check if a supervised job is currently healthy.
     *
     * Returns true if the job has an explicit healthy status. Jobs with
     * no health record or unhealthy status return false.
     *
     * Example:
     * ```php
     * if (Chaperone::isHealthy($supervisionId)) {
     *     Log::info('Job is healthy and running normally');
     * }
     * ```
     *
     * @param  string $supervisionId Supervision session identifier
     * @return bool   True if job is marked as healthy
     */
    public function isHealthy(string $supervisionId): bool
    {
        return $this->healthMonitor->isHealthy($supervisionId);
    }

    /**
     * Get detailed health information for a supervised job.
     *
     * Returns comprehensive health status including current state, reason for
     * unhealthy status (if applicable), timestamps, and check history.
     *
     * Example:
     * ```php
     * $health = Chaperone::getHealth($supervisionId);
     *
     * if ($health['status'] === 'unhealthy') {
     *     Log::warning("Job unhealthy: {$health['reason']}");
     * }
     * ```
     *
     * @param  string                                                                                                       $supervisionId Supervision session identifier
     * @return array{status: string, reason: null|string, updated_at: null|string, job_id: string, check_count: int, first_unhealthy_at?: string} Health status data
     */
    public function getHealth(string $supervisionId): array
    {
        return $this->healthMonitor->getHealth($supervisionId);
    }

    /**
     * Supervise a worker pool for concurrent job processing.
     *
     * Creates or retrieves a worker pool supervisor for managing multiple workers
     * processing jobs from a queue. The supervisor provides pool-level monitoring,
     * worker health tracking, and automatic worker restart capabilities.
     *
     * Example:
     * ```php
     * Chaperone::pool('payment-processors')
     *     ->workers(5)
     *     ->queue('payments')
     *     ->withHealthCheck(fn($worker) => $worker->memoryUsage() < 512)
     *     ->onCrash(fn($worker) => Log::error("Worker {$worker->id} crashed"))
     *     ->supervise();
     * ```
     *
     * @param  string               $name Pool name for identification
     * @return WorkerPoolSupervisor WorkerPoolSupervisor instance
     */
    public function pool(string $name): WorkerPoolSupervisor
    {
        return $this->workerPoolRegistry->get($name);
    }

    /**
     * Configure the supervision dashboard.
     *
     * STUB: Future implementation for configuring a real-time dashboard showing
     * supervision metrics, health status, circuit breaker states, and resource usage.
     *
     * Example (planned):
     * ```php
     * Chaperone::dashboard()
     *     ->withRefreshInterval(5)
     *     ->showMetrics(['health', 'resources', 'circuit_breakers'])
     *     ->enableAlerts()
     *     ->serve();
     * ```
     *
     * @return mixed DashboardConfig instance (to be implemented)
     *
     * @throws \RuntimeException Always throws - method not yet implemented
     */
    public function dashboard(): mixed
    {
        throw new \RuntimeException('Dashboard configuration not yet implemented');
    }

    /**
     * Prepare for graceful deployment with zero-downtime supervision.
     *
     * Creates a deployment coordinator for managing graceful shutdowns during
     * deployments. Ensures all supervised jobs complete or are safely cancelled
     * before the application is restarted, preventing job loss.
     *
     * Example:
     * ```php
     * Chaperone::prepareForDeployment()
     *     ->drainQueues(['default', 'emails'])
     *     ->waitForCompletion(300)
     *     ->cancelLongRunning()
     *     ->onTimeout(fn($jobs) => Log::warning('Deployment timeout', ['jobs' => $jobs->count()]))
     *     ->execute();
     * ```
     *
     * @return DeploymentCoordinator DeploymentCoordinator instance
     */
    public function prepareForDeployment(): DeploymentCoordinator
    {
        return new DeploymentCoordinator();
    }
}
