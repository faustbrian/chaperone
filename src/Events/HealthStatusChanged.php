<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Chaperone\Events;

/**
 * Event dispatched when a job's health status changes.
 *
 * Fired when a supervised job transitions between health states (healthy,
 * unhealthy, unknown). Provides previous and current status along with
 * reason for unhealthy state transitions.
 *
 * Used for alerting, logging, and tracking job health lifecycle. Enables
 * monitoring systems to react to degrading job health or recovery.
 *
 * ```php
 * Event::listen(HealthStatusChanged::class, function ($event) {
 *     if ($event->newStatus === 'unhealthy') {
 *         Alert::warning("Job {$event->jobId} became unhealthy: {$event->reason}");
 *     } elseif ($event->previousStatus === 'unhealthy' && $event->newStatus === 'healthy') {
 *         Alert::info("Job {$event->jobId} recovered");
 *     }
 * });
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 */
final readonly class HealthStatusChanged
{
    /**
     * Create a new health status changed event.
     *
     * @param string      $jobId          Job or supervision identifier
     * @param string      $newStatus      New health status (healthy, unhealthy, unknown)
     * @param string      $previousStatus Previous health status
     * @param null|string $reason         Reason for status change (especially for unhealthy)
     */
    public function __construct(
        public string $jobId,
        public string $newStatus,
        public string $previousStatus,
        public ?string $reason,
    ) {}
}
