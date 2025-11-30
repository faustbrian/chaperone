<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Chaperone\Events;

/**
 * Event dispatched when any resource violation is detected.
 *
 * Generic event fired when a supervised job violates any configured resource
 * constraint. Provides a unified interface for handling various types of
 * violations (memory, CPU, disk, network, etc.) through a single event listener.
 *
 * Complements specific violation events (JobMemoryExceeded, JobCpuExceeded)
 * by offering a catch-all mechanism for resource monitoring. Useful for
 * centralized violation tracking and enforcing consistent violation policies.
 *
 * ```php
 * Event::listen(ResourceViolationDetected::class, function ($event) {
 *     Log::warning("Resource violation", [
 *         'supervision_id' => $event->supervisionId,
 *         'type' => $event->violationType,
 *         'limit' => $event->limit,
 *         'actual' => $event->actual,
 *         'percent_over' => (($event->actual - $event->limit) / $event->limit) * 100,
 *     ]);
 *
 *     // Track all violations in metrics system
 *     Metrics::increment('resource.violations', [
 *         'type' => $event->violationType,
 *     ]);
 *
 *     // Apply universal violation policy
 *     if ($event->actual > $event->limit * 2) {
 *         JobTerminator::kill($event->supervisionId);
 *     }
 * });
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 */
final readonly class ResourceViolationDetected
{
    /**
     * Create a new resource violation detected event.
     *
     * @param string    $supervisionId  Unique identifier for the supervision session
     * @param string    $violationType  Type of resource that was violated (memory, cpu, disk, etc.)
     * @param int|float $limit          The configured resource limit
     * @param int|float $actual         The actual resource usage when violation was detected
     */
    public function __construct(
        public string $supervisionId,
        public string $violationType,
        public int|float $limit,
        public int|float $actual,
    ) {}
}
