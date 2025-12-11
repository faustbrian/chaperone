<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Chaperone\Events;

/**
 * Event dispatched when a job exceeds its CPU usage limit.
 *
 * Fired when a supervised job's CPU usage exceeds the configured threshold.
 * Detected through resource monitoring and used to identify CPU-intensive
 * jobs that may be degrading system performance.
 *
 * Used to trigger throttling, identify inefficient job implementations,
 * and enforce fair resource sharing across multiple jobs. CPU limits are
 * typically specified as percentage of total CPU capacity.
 *
 * ```php
 * Event::listen(JobCpuExceeded::class, function ($event) {
 *     Log::warning("Job CPU exceeded", [
 *         'supervision_id' => $event->supervisionId,
 *         'cpu_limit' => $event->cpuLimit,
 *         'cpu_usage' => $event->cpuUsage,
 *     ]);
 *
 *     // Apply CPU throttling
 *     CpuThrottler::apply($event->supervisionId, maxCpu: $event->cpuLimit);
 *
 *     // Track repeated violations
 *     Metrics::increment('job.cpu_exceeded', [
 *         'supervision_id' => $event->supervisionId,
 *     ]);
 * });
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 * @psalm-immutable
 */
final readonly class JobCpuExceeded
{
    /**
     * Create a new job CPU exceeded event.
     *
     * @param string $supervisionId Unique identifier for the supervision session
     * @param float  $cpuLimit      The configured CPU limit as percentage (0-100)
     * @param float  $cpuUsage      The actual CPU usage percentage when limit was exceeded
     */
    public function __construct(
        public string $supervisionId,
        public float $cpuLimit,
        public float $cpuUsage,
    ) {}
}
