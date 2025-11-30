<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Chaperone\Enums;

/**
 * Defines health status states for supervised jobs.
 *
 * Represents the health and execution state of long-running queue jobs being
 * monitored by Chaperone. Status is determined by heartbeat reports, resource
 * usage, execution time, and error conditions. Used for health monitoring,
 * alerting, and determining intervention strategies.
 *
 * Status determination factors:
 * - Heartbeat freshness: Time since last health report
 * - Resource usage: Memory, CPU, disk space within limits
 * - Execution time: Within configured timeout thresholds
 * - Error conditions: Exception tracking and failure patterns
 * - Circuit breaker state: Open/closed status
 *
 * ```php
 * // Check job health
 * $status = JobHealthStatus::fromJob($supervisedJob);
 *
 * // Filter unhealthy jobs
 * SupervisedJob::whereIn('health_status', [
 *     JobHealthStatus::Unhealthy->value,
 *     JobHealthStatus::Stuck->value,
 * ])->get();
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 */
enum JobHealthStatus: string
{
    /**
     * Job is executing normally within all configured thresholds.
     *
     * Heartbeats are being reported on schedule, resource usage is within limits,
     * execution time is reasonable, and no errors have been detected. This is the
     * ideal state for a supervised job.
     */
    case Healthy = 'healthy';

    /**
     * Job is exhibiting warning signs but still executing.
     *
     * One or more concerning conditions exist such as: approaching resource limits,
     * slow heartbeat reports, elevated error rates, or nearing timeout thresholds.
     * The job is still running but requires monitoring and may need intervention.
     */
    case Unhealthy = 'unhealthy';

    /**
     * Job health status cannot be determined.
     *
     * Insufficient data exists to determine job health, typically during initial
     * startup before first heartbeat or when supervision has just been enabled.
     * Jobs in this state require additional monitoring cycles to establish status.
     */
    case Unknown = 'unknown';

    /**
     * Job appears to be deadlocked or making no progress.
     *
     * No heartbeat has been received within the configured interval, job has
     * exceeded timeout thresholds, or appears frozen despite consuming resources.
     * Requires immediate intervention such as termination and restart.
     */
    case Stuck = 'stuck';

    /**
     * Job has exceeded configured execution time limit.
     *
     * The job has run longer than the configured timeout threshold. This may
     * indicate an infinite loop, external service delays, or underestimated
     * time requirements. The job should be terminated and investigated.
     */
    case Timeout = 'timeout';

    /**
     * Determine if this status represents a healthy state.
     *
     * @return bool True if the job is healthy
     */
    public function isHealthy(): bool
    {
        return $this === self::Healthy;
    }

    /**
     * Determine if this status represents an unhealthy state.
     *
     * @return bool True if the job is unhealthy
     */
    public function isUnhealthy(): bool
    {
        return $this === self::Unhealthy;
    }

    /**
     * Determine if this status indicates the job requires immediate intervention.
     *
     * Stuck and Timeout states require immediate action such as job termination.
     *
     * @return bool True if intervention is required
     */
    public function requiresIntervention(): bool
    {
        return $this === self::Stuck || $this === self::Timeout;
    }

    /**
     * Determine if this status represents a terminal failure state.
     *
     * @return bool True if the job has failed terminally
     */
    public function isTerminal(): bool
    {
        return $this === self::Stuck || $this === self::Timeout;
    }

    /**
     * Determine if this status is indeterminate.
     *
     * @return bool True if the status cannot be determined
     */
    public function isUnknown(): bool
    {
        return $this === self::Unknown;
    }

    /**
     * Get a human-readable label for this status.
     *
     * @return string The display label
     */
    public function label(): string
    {
        return match ($this) {
            self::Healthy => 'Healthy',
            self::Unhealthy => 'Unhealthy',
            self::Unknown => 'Unknown',
            self::Stuck => 'Stuck',
            self::Timeout => 'Timeout',
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
            self::Healthy => 'green',
            self::Unhealthy => 'yellow',
            self::Unknown => 'gray',
            self::Stuck => 'red',
            self::Timeout => 'orange',
        };
    }

    /**
     * Get a description of what this status means.
     *
     * @return string Human-readable description
     */
    public function description(): string
    {
        return match ($this) {
            self::Healthy => 'Job is executing normally within all thresholds',
            self::Unhealthy => 'Job is exhibiting warning signs but still executing',
            self::Unknown => 'Insufficient data to determine job health',
            self::Stuck => 'Job appears deadlocked or making no progress',
            self::Timeout => 'Job has exceeded configured execution time limit',
        };
    }

    /**
     * Get the severity level for alerting purposes.
     *
     * @return string Severity level: info, warning, error, critical
     */
    public function severity(): string
    {
        return match ($this) {
            self::Healthy => 'info',
            self::Unhealthy => 'warning',
            self::Unknown => 'info',
            self::Stuck => 'critical',
            self::Timeout => 'error',
        };
    }
}
