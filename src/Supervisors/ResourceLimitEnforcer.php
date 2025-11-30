<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Chaperone\Supervisors;

use Cline\Chaperone\Contracts\ResourceMonitor;
use Cline\Chaperone\Events\ResourceViolationDetected;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;

use function config;
use function disk_free_space;
use function memory_get_usage;
use function sys_getloadavg;

/**
 * Enforces resource limits for supervised jobs.
 *
 * Monitors memory, CPU, and disk usage for supervised jobs and detects violations
 * of configured limits. Records violations to cache for audit trails and fires
 * events for alerting and logging.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class ResourceLimitEnforcer implements ResourceMonitor
{
    /**
     * Cache key prefix for resource violation records.
     */
    private const string VIOLATION_PREFIX = 'chaperone:violation:';

    /**
     * Cache key prefix for current resource usage.
     */
    private const string USAGE_PREFIX = 'chaperone:usage:';

    /**
     * Check memory usage against configured limits.
     *
     * Monitors current memory consumption and compares against the configured
     * limit. Records violations and fires ResourceViolationDetected event when
     * limit is exceeded.
     *
     * @param  string                                                                      $jobId Job or supervision identifier
     * @return array{within_limit: bool, current_mb: int, limit_mb: null|int, percent: float} Memory check results
     */
    public function checkMemory(string $jobId): array
    {
        $currentBytes = memory_get_usage(true);
        $currentMb = (int) ($currentBytes / 1024 / 1024);

        /** @var null|int $limitMb */
        $limitMb = config('chaperone.supervision.memory_limit_mb');

        $withinLimit = true;
        $percent = 0.0;

        if ($limitMb !== null) {
            $percent = ($currentMb / $limitMb) * 100;
            $withinLimit = $currentMb <= $limitMb;

            if (!$withinLimit) {
                $this->recordViolation($jobId, 'memory', [
                    'current_mb' => $currentMb,
                    'limit_mb' => $limitMb,
                    'percent' => $percent,
                ]);

                Event::dispatch(new ResourceViolationDetected(
                    $jobId,
                    'memory',
                    $limitMb,
                    $currentMb,
                ));
            }
        }

        $result = [
            'within_limit' => $withinLimit,
            'current_mb' => $currentMb,
            'limit_mb' => $limitMb,
            'percent' => $percent,
        ];

        $this->recordUsage($jobId, 'memory', $result);

        return $result;
    }

    /**
     * Check CPU usage against configured limits.
     *
     * Monitors current CPU load and compares against the configured limit.
     * Records violations and fires ResourceViolationDetected event when
     * limit is exceeded. Uses system load average for CPU measurement.
     *
     * @param  string                                                                              $jobId Job or supervision identifier
     * @return array{within_limit: bool, current_percent: float, limit_percent: null|float, load_avg: float} CPU check results
     */
    public function checkCpu(string $jobId): array
    {
        $loadAvg = sys_getloadavg();
        $currentPercent = $loadAvg !== false ? $loadAvg[0] * 100 : 0.0;

        /** @var null|float $limitPercent */
        $limitPercent = config('chaperone.supervision.cpu_limit_percent');

        $withinLimit = true;

        if ($limitPercent !== null) {
            $withinLimit = $currentPercent <= $limitPercent;

            if (!$withinLimit) {
                $this->recordViolation($jobId, 'cpu', [
                    'current_percent' => $currentPercent,
                    'limit_percent' => $limitPercent,
                    'load_avg' => $loadAvg[0] ?? 0.0,
                ]);

                Event::dispatch(new ResourceViolationDetected(
                    $jobId,
                    'cpu',
                    $limitPercent,
                    $currentPercent,
                ));
            }
        }

        $result = [
            'within_limit' => $withinLimit,
            'current_percent' => $currentPercent,
            'limit_percent' => $limitPercent,
            'load_avg' => $loadAvg[0] ?? 0.0,
        ];

        $this->recordUsage($jobId, 'cpu', $result);

        return $result;
    }

    /**
     * Check disk usage against configured limits.
     *
     * Monitors available disk space and compares against the configured limit.
     * Records violations and fires ResourceViolationDetected event when
     * limit is exceeded.
     *
     * @param  string                                                                      $jobId Job or supervision identifier
     * @return array{within_limit: bool, current_mb: int, limit_mb: null|int, free_mb: int} Disk check results
     */
    public function checkDisk(string $jobId): array
    {
        /** @var string $path */
        $path = config('chaperone.supervision.disk_path', '/');

        $freeBytes = disk_free_space($path);
        $freeMb = $freeBytes !== false ? (int) ($freeBytes / 1024 / 1024) : 0;

        /** @var null|int $limitMb */
        $limitMb = config('chaperone.supervision.disk_limit_mb');

        $withinLimit = true;
        $currentMb = 0;

        if ($limitMb !== null) {
            // Calculate used space (assuming we're checking if usage exceeds limit)
            // In practice, you might want to track total vs used differently
            $withinLimit = $freeMb >= $limitMb;
            $currentMb = $limitMb - $freeMb; // Approximation of used space

            if (!$withinLimit) {
                $this->recordViolation($jobId, 'disk', [
                    'current_mb' => $currentMb,
                    'limit_mb' => $limitMb,
                    'free_mb' => $freeMb,
                ]);

                Event::dispatch(new ResourceViolationDetected(
                    $jobId,
                    'disk',
                    $limitMb,
                    $currentMb,
                ));
            }
        }

        $result = [
            'within_limit' => $withinLimit,
            'current_mb' => $currentMb,
            'limit_mb' => $limitMb,
            'free_mb' => $freeMb,
        ];

        $this->recordUsage($jobId, 'disk', $result);

        return $result;
    }

    /**
     * Check if all resource metrics are within configured limits.
     *
     * Performs comprehensive resource check across memory, CPU, and disk.
     * Returns true only if all monitored resources are within their limits.
     *
     * @param  string $jobId Job or supervision identifier
     * @return bool   True if all resources are within limits
     */
    public function isWithinLimits(string $jobId): bool
    {
        $memoryCheck = $this->checkMemory($jobId);
        $cpuCheck = $this->checkCpu($jobId);
        $diskCheck = $this->checkDisk($jobId);

        return $memoryCheck['within_limit']
            && $cpuCheck['within_limit']
            && $diskCheck['within_limit'];
    }

    /**
     * Get current resource usage for all monitored metrics.
     *
     * Returns a comprehensive snapshot of current resource consumption including
     * memory, CPU, and disk usage with their respective limits and status.
     *
     * @param  string                                                     $jobId Job or supervision identifier
     * @return array{memory: array, cpu: array, disk: array, all_within_limits: bool} Current usage metrics
     */
    public function getCurrentUsage(string $jobId): array
    {
        $memory = $this->checkMemory($jobId);
        $cpu = $this->checkCpu($jobId);
        $disk = $this->checkDisk($jobId);

        return [
            'memory' => $memory,
            'cpu' => $cpu,
            'disk' => $disk,
            'all_within_limits' => $memory['within_limit']
                && $cpu['within_limit']
                && $disk['within_limit'],
        ];
    }

    /**
     * Get violation history for a job.
     *
     * Retrieves all recorded resource violations for the specified job,
     * useful for debugging and audit trails.
     *
     * @param  string                                                                       $jobId Job or supervision identifier
     * @return array<array{resource_type: string, data: array, timestamp: string, job_id: string}> Violation records
     */
    public function getViolations(string $jobId): array
    {
        /** @var array<array{resource_type: string, data: array, timestamp: string, job_id: string}> $violations */
        $violations = Cache::get(self::VIOLATION_PREFIX.$jobId, []);

        return $violations;
    }

    /**
     * Clear violation history for a job.
     *
     * Removes all recorded violations from cache. Used for cleanup when
     * jobs complete or during testing.
     *
     * @param string $jobId Job or supervision identifier
     */
    public function clearViolations(string $jobId): void
    {
        Cache::forget(self::VIOLATION_PREFIX.$jobId);
        Cache::forget(self::USAGE_PREFIX.$jobId);
    }

    /**
     * Record a resource violation to cache.
     *
     * Stores violation information with timestamp for audit trails and
     * historical analysis. Violations are stored in cache with TTL.
     *
     * @param string $jobId        Job or supervision identifier
     * @param string $resourceType Type of resource (memory, cpu, disk)
     * @param array  $data         Violation details and metrics
     */
    private function recordViolation(string $jobId, string $resourceType, array $data): void
    {
        /** @var array<array{resource_type: string, data: array, timestamp: string, job_id: string}> $violations */
        $violations = Cache::get(self::VIOLATION_PREFIX.$jobId, []);

        $violations[] = [
            'resource_type' => $resourceType,
            'data' => $data,
            'timestamp' => Date::now()->toIso8601String(),
            'job_id' => $jobId,
        ];

        /** @var int $ttl */
        $ttl = config('chaperone.supervision.violation_ttl_seconds', 86400); // 24 hours default

        Cache::put(self::VIOLATION_PREFIX.$jobId, $violations, $ttl);
    }

    /**
     * Record current resource usage to cache.
     *
     * Stores current usage metrics for trend analysis and monitoring.
     * Keeps only the latest usage data to avoid cache bloat.
     *
     * @param string $jobId        Job or supervision identifier
     * @param string $resourceType Type of resource (memory, cpu, disk)
     * @param array  $data         Current usage metrics
     */
    private function recordUsage(string $jobId, string $resourceType, array $data): void
    {
        /** @var array<string, array> $usage */
        $usage = Cache::get(self::USAGE_PREFIX.$jobId, []);

        $usage[$resourceType] = [
            'data' => $data,
            'timestamp' => Date::now()->toIso8601String(),
        ];

        /** @var int $ttl */
        $ttl = config('chaperone.supervision.usage_ttl_seconds', 3600); // 1 hour default

        Cache::put(self::USAGE_PREFIX.$jobId, $usage, $ttl);
    }
}
