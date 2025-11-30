<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Chaperone\Events;

/**
 * Event dispatched when a job exceeds its memory limit.
 *
 * Fired when a supervised job's memory usage exceeds the configured threshold.
 * Detected through resource monitoring and used to prevent jobs from consuming
 * excessive memory and potentially crashing the system.
 *
 * Used to trigger memory leak alerts, terminate memory-hungry jobs before
 * they affect system stability, and collect metrics about memory consumption
 * patterns across different job types.
 *
 * ```php
 * Event::listen(JobMemoryExceeded::class, function ($event) {
 *     Log::error("Job memory exceeded", [
 *         'supervision_id' => $event->supervisionId,
 *         'memory_limit' => $event->memoryLimit,
 *         'memory_usage' => $event->memoryUsage,
 *         'percent_over' => (($event->memoryUsage - $event->memoryLimit) / $event->memoryLimit) * 100,
 *     ]);
 *
 *     // Terminate job if memory usage is critical
 *     if ($event->memoryUsage > $event->memoryLimit * 1.5) {
 *         JobTerminator::kill($event->supervisionId);
 *     }
 * });
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 */
final readonly class JobMemoryExceeded
{
    /**
     * Create a new job memory exceeded event.
     *
     * @param string $supervisionId Unique identifier for the supervision session
     * @param int    $memoryLimit   The configured memory limit in bytes
     * @param int    $memoryUsage   The actual memory usage in bytes when limit was exceeded
     */
    public function __construct(
        public string $supervisionId,
        public int $memoryLimit,
        public int $memoryUsage,
    ) {}
}
