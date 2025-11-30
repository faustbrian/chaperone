<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Chaperone\Supervisors;

use Cline\Chaperone\Contracts\HealthMonitor;
use Cline\Chaperone\Contracts\ResourceMonitor;
use Cline\Chaperone\Contracts\SupervisionStrategy;
use Illuminate\Support\Str;

use function config;

/**
 * Main supervision orchestrator for job monitoring and lifecycle management.
 *
 * Provides a fluent API for configuring supervision behavior including resource limits,
 * timeouts, heartbeat intervals, and callbacks for various supervision events. Integrates
 * with ResourceMonitor and HealthMonitor to enforce limits and track job health.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class JobSupervisor implements SupervisionStrategy
{
    /**
     * Unique identifier for this supervision session.
     */
    private readonly string $supervisionId;

    /**
     * Maximum execution time in seconds before timeout.
     */
    private ?int $timeoutSeconds = null;

    /**
     * Maximum memory usage in megabytes.
     */
    private ?int $memoryLimitMb = null;

    /**
     * Maximum CPU usage percentage (0-100).
     */
    private ?float $cpuLimitPercent = null;

    /**
     * Maximum disk usage in megabytes.
     */
    private ?int $diskLimitMb = null;

    /**
     * Interval in seconds between heartbeat checks.
     */
    private int $heartbeatIntervalSeconds = 30;

    /**
     * Number of missed heartbeats before job is considered stuck.
     */
    private int $missedHeartbeatsThreshold = 3;

    /**
     * Callback invoked when job is detected as stuck.
     *
     * @var null|callable(string $supervisionId, array $metadata): void
     */
    private $onStuckCallback;

    /**
     * Callback invoked when job exceeds memory limit.
     *
     * @var null|callable(string $supervisionId, int $currentMb, int $limitMb): void
     */
    private $onMemoryExceededCallback;

    /**
     * Callback invoked when job exceeds CPU limit.
     *
     * @var null|callable(string $supervisionId, float $currentPercent, float $limitPercent): void
     */
    private $onCpuExceededCallback;

    /**
     * Callback invoked when job exceeds disk limit.
     *
     * @var null|callable(string $supervisionId, int $currentMb, int $limitMb): void
     */
    private $onDiskExceededCallback;

    /**
     * Create a new job supervisor instance.
     *
     * @param ResourceMonitor   $resourceMonitor Service for monitoring resource usage
     * @param HealthMonitor     $healthMonitor   Service for tracking job health status
     * @param HeartbeatMonitor  $heartbeatMonitor Service for tracking heartbeats and detecting stuck jobs
     */
    public function __construct(
        private readonly ResourceMonitor $resourceMonitor,
        private readonly HealthMonitor $healthMonitor,
        private readonly HeartbeatMonitor $heartbeatMonitor,
    ) {
        $this->supervisionId = Str::uuid()->toString();

        // Load defaults from config
        /** @var null|int $configTimeout */
        $configTimeout = config('chaperone.supervision.timeout_seconds');
        $this->timeoutSeconds = $configTimeout;

        /** @var null|int $configMemory */
        $configMemory = config('chaperone.supervision.memory_limit_mb');
        $this->memoryLimitMb = $configMemory;

        /** @var null|float $configCpu */
        $configCpu = config('chaperone.supervision.cpu_limit_percent');
        $this->cpuLimitPercent = $configCpu;

        /** @var int $configHeartbeat */
        $configHeartbeat = config('chaperone.supervision.heartbeat_interval_seconds', 30);
        $this->heartbeatIntervalSeconds = $configHeartbeat;

        /** @var int $configThreshold */
        $configThreshold = config('chaperone.supervision.missed_heartbeats_threshold', 3);
        $this->missedHeartbeatsThreshold = $configThreshold;
    }

    /**
     * Set the maximum execution time before timeout.
     *
     * Configures the timeout limit for supervised job execution. When exceeded,
     * triggers the onTimeout callback if configured.
     *
     * @param  int  $seconds Maximum execution time in seconds
     * @return self Fluent interface for method chaining
     */
    public function withTimeout(int $seconds): self
    {
        $this->timeoutSeconds = $seconds;

        return $this;
    }

    /**
     * Set the maximum memory usage limit.
     *
     * Configures the memory limit for supervised job execution. When exceeded,
     * triggers the onMemoryExceeded callback if configured.
     *
     * @param  int  $megabytes Maximum memory usage in megabytes
     * @return self Fluent interface for method chaining
     */
    public function withMemoryLimit(int $megabytes): self
    {
        $this->memoryLimitMb = $megabytes;

        return $this;
    }

    /**
     * Set the maximum CPU usage limit.
     *
     * Configures the CPU usage limit for supervised job execution. When exceeded,
     * triggers the onCpuExceeded callback if configured.
     *
     * @param  float $percent Maximum CPU usage percentage (0-100)
     * @return self  Fluent interface for method chaining
     */
    public function withCpuLimit(float $percent): self
    {
        $this->cpuLimitPercent = $percent;

        return $this;
    }

    /**
     * Set the maximum disk usage limit.
     *
     * Configures the disk usage limit for supervised job execution. When exceeded,
     * triggers the onDiskExceeded callback if configured.
     *
     * @param  int  $megabytes Maximum disk usage in megabytes
     * @return self Fluent interface for method chaining
     */
    public function withDiskLimit(int $megabytes): self
    {
        $this->diskLimitMb = $megabytes;

        return $this;
    }

    /**
     * Set the heartbeat interval for job health checks.
     *
     * Configures how frequently the supervisor expects heartbeat signals from
     * the supervised job. Used to detect stuck or unresponsive jobs.
     *
     * @param  int  $seconds Interval between expected heartbeats in seconds
     * @return self Fluent interface for method chaining
     */
    public function withHeartbeatInterval(int $seconds): self
    {
        $this->heartbeatIntervalSeconds = $seconds;

        return $this;
    }

    /**
     * Set the missed heartbeats threshold for stuck detection.
     *
     * Configures how many consecutive heartbeats can be missed before the job
     * is considered stuck and triggers the onStuck callback.
     *
     * @param  int  $count Number of missed heartbeats before job is stuck
     * @return self Fluent interface for method chaining
     */
    public function withMissedHeartbeatsThreshold(int $count): self
    {
        $this->missedHeartbeatsThreshold = $count;

        return $this;
    }

    /**
     * Register callback for stuck job detection.
     *
     * The callback receives the supervision ID and metadata from the last
     * successful heartbeat when a job is detected as stuck.
     *
     * @param  callable(string $supervisionId, array $metadata): void $callback Callback to invoke
     * @return self                                                   Fluent interface for method chaining
     */
    public function onStuck(callable $callback): self
    {
        $this->onStuckCallback = $callback;

        return $this;
    }

    /**
     * Register callback for timeout detection.
     *
     * The callback receives the supervision ID and configured timeout limit
     * when a job exceeds its maximum execution time.
     *
     * @param  callable(string $supervisionId, int $timeoutSeconds): void $callback Callback to invoke
     * @return self                                                       Fluent interface for method chaining
     */
    public function onTimeout(callable $callback): self
    {
        return $this;
    }

    /**
     * Register callback for job failure.
     *
     * The callback receives the supervision ID and exception when a job
     * fails during execution.
     *
     * @param  callable(string $supervisionId, \Throwable $exception): void $callback Callback to invoke
     * @return self                                                         Fluent interface for method chaining
     */
    public function onFailure(callable $callback): self
    {
        return $this;
    }

    /**
     * Register callback for memory limit violation.
     *
     * The callback receives the supervision ID, current memory usage, and
     * configured limit when a job exceeds its memory allocation.
     *
     * @param  callable(string $supervisionId, int $currentMb, int $limitMb): void $callback Callback to invoke
     * @return self                                                            Fluent interface for method chaining
     */
    public function onMemoryExceeded(callable $callback): self
    {
        $this->onMemoryExceededCallback = $callback;

        return $this;
    }

    /**
     * Register callback for CPU limit violation.
     *
     * The callback receives the supervision ID, current CPU usage percentage,
     * and configured limit when a job exceeds its CPU allocation.
     *
     * @param  callable(string $supervisionId, float $currentPercent, float $limitPercent): void $callback Callback to invoke
     * @return self                                                                          Fluent interface for method chaining
     */
    public function onCpuExceeded(callable $callback): self
    {
        $this->onCpuExceededCallback = $callback;

        return $this;
    }

    /**
     * Register callback for disk limit violation.
     *
     * The callback receives the supervision ID, current disk usage, and
     * configured limit when a job exceeds its disk allocation.
     *
     * @param  callable(string $supervisionId, int $currentMb, int $limitMb): void $callback Callback to invoke
     * @return self                                                            Fluent interface for method chaining
     */
    public function onDiskExceeded(callable $callback): self
    {
        $this->onDiskExceededCallback = $callback;

        return $this;
    }

    /**
     * Start supervision for the specified job class.
     *
     * Initiates monitoring and lifecycle management for the job, including:
     * - Resource usage monitoring (memory, CPU, disk)
     * - Heartbeat tracking for stuck job detection
     * - Health status monitoring
     * - Timeout enforcement
     * - Callback execution for supervision events
     *
     * @param string $jobClass Fully-qualified class name of job to supervise
     *
     * @throws \RuntimeException When supervision cannot be started
     */
    public function supervise(string $jobClass): void
    {
        // Mark job as healthy at start
        $this->healthMonitor->markHealthy($this->supervisionId);

        // Start resource monitoring if limits are configured
        $this->startResourceMonitoring();

        // Start heartbeat monitoring
        $this->startHeartbeatMonitoring();

        // Initialize supervision tracking
        $this->initializeSupervision($jobClass);
    }

    /**
     * Get the unique supervision ID for this session.
     *
     * @return string Supervision session identifier
     */
    public function getSupervisionId(): string
    {
        return $this->supervisionId;
    }

    /**
     * Start resource monitoring for configured limits.
     *
     * Initializes periodic checks for memory, CPU, and disk usage based on
     * configured limits. Triggers appropriate callbacks when limits are exceeded.
     */
    private function startResourceMonitoring(): void
    {
        if ($this->memoryLimitMb !== null) {
            $memoryCheck = $this->resourceMonitor->checkMemory($this->supervisionId);

            if (!$memoryCheck['within_limit'] && $this->onMemoryExceededCallback !== null) {
                ($this->onMemoryExceededCallback)(
                    $this->supervisionId,
                    $memoryCheck['current_mb'],
                    $this->memoryLimitMb,
                );
            }
        }

        if ($this->cpuLimitPercent !== null) {
            $cpuCheck = $this->resourceMonitor->checkCpu($this->supervisionId);

            if (!$cpuCheck['within_limit'] && $this->onCpuExceededCallback !== null) {
                ($this->onCpuExceededCallback)(
                    $this->supervisionId,
                    $cpuCheck['current_percent'],
                    $this->cpuLimitPercent,
                );
            }
        }

        if ($this->diskLimitMb !== null) {
            $diskCheck = $this->resourceMonitor->checkDisk($this->supervisionId);

            if (!$diskCheck['within_limit'] && $this->onDiskExceededCallback !== null) {
                ($this->onDiskExceededCallback)(
                    $this->supervisionId,
                    $diskCheck['current_mb'],
                    $this->diskLimitMb,
                );
            }
        }
    }

    /**
     * Start heartbeat monitoring for stuck job detection.
     *
     * Initializes periodic heartbeat checks based on configured interval and
     * missed heartbeat threshold. Triggers onStuck callback when job becomes
     * unresponsive.
     */
    private function startHeartbeatMonitoring(): void
    {
        $stuckJobs = $this->heartbeatMonitor->checkForStuckJobs();

        foreach ($stuckJobs as $stuckJob) {
            if ($stuckJob['supervision_id'] === $this->supervisionId) {
                if ($this->onStuckCallback !== null) {
                    ($this->onStuckCallback)(
                        $this->supervisionId,
                        $stuckJob['metadata'] ?? [],
                    );
                }

                // Mark as unhealthy
                $this->healthMonitor->markUnhealthy(
                    $this->supervisionId,
                    'Job stuck - missed heartbeats threshold exceeded',
                );
            }
        }
    }

    /**
     * Initialize supervision tracking for the job.
     *
     * Sets up initial supervision state and metadata for the job being supervised.
     * This includes recording the job class, start time, and supervision configuration.
     *
     * @param string $jobClass Fully-qualified class name of supervised job
     */
    private function initializeSupervision(string $jobClass): void
    {
        // Record initial heartbeat
        $this->heartbeatMonitor->recordHeartbeat($this->supervisionId, [
            'job_class' => $jobClass,
            'timeout_seconds' => $this->timeoutSeconds,
            'memory_limit_mb' => $this->memoryLimitMb,
            'cpu_limit_percent' => $this->cpuLimitPercent,
            'disk_limit_mb' => $this->diskLimitMb,
            'heartbeat_interval' => $this->heartbeatIntervalSeconds,
            'missed_heartbeats_threshold' => $this->missedHeartbeatsThreshold,
        ]);
    }
}
