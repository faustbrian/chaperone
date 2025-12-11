<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Chaperone\Supervisors;

use Cline\Chaperone\Contracts\HealthMonitor;
use Cline\Chaperone\Events\HealthStatusChanged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;

use function array_filter;
use function array_values;
use function config;
use function end;
use function in_array;

/**
 * Manages health checks for supervised jobs.
 *
 * Tracks health status of supervised jobs through periodic checks and explicit
 * status updates. Persists health information to cache and fires events when
 * health status transitions occur.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class HealthCheckManager implements HealthMonitor
{
    /**
     * Cache key prefix for health status records.
     */
    private const string HEALTH_PREFIX = 'chaperone:health:';

    /**
     * Cache key for all tracked health records.
     */
    private const string HEALTH_INDEX_KEY = 'chaperone:health_index';

    /**
     * Health status constants.
     */
    private const string STATUS_HEALTHY = 'healthy';

    private const string STATUS_UNHEALTHY = 'unhealthy';

    private const string STATUS_UNKNOWN = 'unknown';

    /**
     * Check if a job is currently healthy.
     *
     * Returns true only if the job has an explicit healthy status recorded.
     * Jobs with no health record or unhealthy status return false.
     *
     * @param  string $jobId Job or supervision identifier
     * @return bool   True if job is marked as healthy
     */
    public function isHealthy(string $jobId): bool
    {
        $health = $this->getHealth($jobId);

        return $health['status'] === self::STATUS_HEALTHY;
    }

    /**
     * Mark a job as healthy.
     *
     * Records a healthy status with timestamp and fires HealthStatusChanged
     * event if the status has changed from a previous state.
     *
     * @param string $jobId Job or supervision identifier
     */
    public function markHealthy(string $jobId): void
    {
        $previousHealth = $this->getHealth($jobId);
        $wasHealthy = $previousHealth['status'] === self::STATUS_HEALTHY;

        $healthData = [
            'status' => self::STATUS_HEALTHY,
            'reason' => null,
            'updated_at' => Date::now()->toIso8601String(),
            'job_id' => $jobId,
            'check_count' => ($previousHealth['check_count'] ?? 0) + 1,
        ];

        $this->recordHealth($jobId, $healthData);

        if ($wasHealthy) {
            return;
        }

        Event::dispatch(
            new HealthStatusChanged(
                $jobId,
                self::STATUS_HEALTHY,
                $previousHealth['status'] ?? self::STATUS_UNKNOWN,
                null,
            )
        );
    }

    /**
     * Mark a job as unhealthy with a reason.
     *
     * Records an unhealthy status with timestamp and reason, then fires
     * HealthStatusChanged event if the status has changed from a previous state.
     *
     * @param string $jobId  Job or supervision identifier
     * @param string $reason Explanation of why the job is unhealthy
     */
    public function markUnhealthy(string $jobId, string $reason): void
    {
        $previousHealth = $this->getHealth($jobId);
        $wasUnhealthy = $previousHealth['status'] === self::STATUS_UNHEALTHY
            && ($previousHealth['reason'] ?? null) === $reason;

        $healthData = [
            'status' => self::STATUS_UNHEALTHY,
            'reason' => $reason,
            'updated_at' => Date::now()->toIso8601String(),
            'job_id' => $jobId,
            'check_count' => ($previousHealth['check_count'] ?? 0) + 1,
            'first_unhealthy_at' => $previousHealth['status'] !== self::STATUS_UNHEALTHY
                ? Date::now()->toIso8601String()
                : ($previousHealth['first_unhealthy_at'] ?? Date::now()->toIso8601String()),
        ];

        $this->recordHealth($jobId, $healthData);

        if ($wasUnhealthy) {
            return;
        }

        Event::dispatch(
            new HealthStatusChanged(
                $jobId,
                self::STATUS_UNHEALTHY,
                $previousHealth['status'] ?? self::STATUS_UNKNOWN,
                $reason,
            )
        );
    }

    /**
     * Get complete health information for a job.
     *
     * Returns comprehensive health status including current state, reason for
     * unhealthy status, timestamps, and check history.
     *
     * @param  string                                                                                                                             $jobId Job or supervision identifier
     * @return array{status: string, reason: null|string, updated_at: null|string, job_id: string, check_count: int, first_unhealthy_at?: string} Health status data
     */
    public function getHealth(string $jobId): array
    {
        /** @var null|array{status: string, reason: null|string, updated_at: string, job_id: string, check_count: int, first_unhealthy_at?: string} $health */
        $health = Cache::get(self::HEALTH_PREFIX.$jobId);

        if ($health === null) {
            return [
                'status' => self::STATUS_UNKNOWN,
                'reason' => null,
                'updated_at' => null,
                'job_id' => $jobId,
                'check_count' => 0,
            ];
        }

        return $health;
    }

    /**
     * Get health status for all tracked jobs.
     *
     * Returns a collection of health records for all jobs currently being
     * monitored. Useful for dashboard views and system-wide health checks.
     *
     * @return array<string, array{status: string, reason: null|string, updated_at: string, job_id: string, check_count: int, first_unhealthy_at?: string}> Map of job IDs to health data
     */
    public function getAllHealth(): array
    {
        /** @var array<string> $index */
        $index = Cache::get(self::HEALTH_INDEX_KEY, []);

        $allHealth = [];

        foreach ($index as $jobId) {
            $health = $this->getHealth($jobId);

            if ($health['status'] === self::STATUS_UNKNOWN) {
                continue;
            }

            $allHealth[$jobId] = $health;
        }

        return $allHealth;
    }

    /**
     * Get all unhealthy jobs.
     *
     * Filters tracked jobs to return only those currently marked as unhealthy.
     * Useful for alerting and triage workflows.
     *
     * @return array<string, array{status: string, reason: null|string, updated_at: string, job_id: string, check_count: int, first_unhealthy_at?: string}> Map of unhealthy job IDs to health data
     */
    public function getUnhealthyJobs(): array
    {
        $allHealth = $this->getAllHealth();

        return array_filter(
            $allHealth,
            fn (array $health): bool => $health['status'] === self::STATUS_UNHEALTHY,
        );
    }

    /**
     * Perform periodic health check for a job.
     *
     * Executes a health check by verifying heartbeat freshness and resource
     * compliance. Updates health status based on check results.
     *
     * @param string           $jobId            Job or supervision identifier
     * @param HeartbeatMonitor $heartbeatMonitor Heartbeat monitoring service
     * @param ResourceMonitor  $resourceMonitor  Resource monitoring service
     */
    public function performHealthCheck(
        string $jobId,
        HeartbeatMonitor $heartbeatMonitor,
        ResourceLimitEnforcer $resourceMonitor,
    ): void {
        $heartbeatData = $heartbeatMonitor->getHeartbeatData($jobId);

        // Check if heartbeat is stale
        if ($heartbeatData === null) {
            $this->markUnhealthy($jobId, 'No heartbeat data found');

            return;
        }

        $lastHeartbeat = Date::parse($heartbeatData['last_heartbeat_at']);

        /** @var int $interval */
        $interval = $heartbeatData['metadata']['heartbeat_interval']
            ?? config('chaperone.supervision.heartbeat_interval_seconds', 30);

        $expectedNextHeartbeat = $lastHeartbeat->addSeconds($interval * 2); // Allow 2x interval grace period

        if (Date::now()->greaterThan($expectedNextHeartbeat)) {
            $this->markUnhealthy($jobId, 'Heartbeat is stale');

            return;
        }

        // Check resource compliance
        if (!$resourceMonitor->isWithinLimits($jobId)) {
            $violations = $resourceMonitor->getViolations($jobId);
            $latestViolation = end($violations);

            if ($latestViolation !== false) {
                $this->markUnhealthy(
                    $jobId,
                    'Resource violation: '.$latestViolation['resource_type'],
                );

                return;
            }
        }

        // All checks passed
        $this->markHealthy($jobId);
    }

    /**
     * Remove health tracking for a job.
     *
     * Cleans up health records when a job completes or is terminated.
     * Removes from both individual health cache and the tracking index.
     *
     * @param string $jobId Job or supervision identifier
     */
    public function removeHealth(string $jobId): void
    {
        Cache::forget(self::HEALTH_PREFIX.$jobId);

        /** @var array<string> $index */
        $index = Cache::get(self::HEALTH_INDEX_KEY, []);

        $index = array_filter($index, fn (string $id): bool => $id !== $jobId);

        Cache::put(self::HEALTH_INDEX_KEY, array_values($index));
    }

    /**
     * Clear all health records.
     *
     * Removes all health tracking data from cache. Used for cleanup during
     * testing or system reset.
     */
    public function clearAll(): void
    {
        /** @var array<string> $index */
        $index = Cache::get(self::HEALTH_INDEX_KEY, []);

        foreach ($index as $jobId) {
            $this->removeHealth($jobId);
        }

        Cache::forget(self::HEALTH_INDEX_KEY);
    }

    /**
     * Record health status to cache.
     *
     * Persists health information with TTL and maintains the health index
     * for efficient querying of all tracked jobs.
     *
     * @param string                                                                                                                        $jobId      Job or supervision identifier
     * @param array{status: string, reason: null|string, updated_at: string, job_id: string, check_count: int, first_unhealthy_at?: string} $healthData Health status information
     */
    private function recordHealth(string $jobId, array $healthData): void
    {
        /** @var int $ttl */
        $ttl = config('chaperone.supervision.health_ttl_seconds', 3_600);

        Cache::put(self::HEALTH_PREFIX.$jobId, $healthData, $ttl);

        // Add to index
        /** @var array<string> $index */
        $index = Cache::get(self::HEALTH_INDEX_KEY, []);

        if (in_array($jobId, $index, true)) {
            return;
        }

        $index[] = $jobId;
        Cache::put(self::HEALTH_INDEX_KEY, $index);
    }
}
