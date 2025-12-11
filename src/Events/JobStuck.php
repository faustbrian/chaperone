<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Chaperone\Events;

use DateTimeImmutable;

/**
 * Event dispatched when a job is detected as stuck.
 *
 * Fired when a supervised job has been running for longer than expected
 * and heartbeats (if any) indicate no progress is being made. Differs from
 * HeartbeatMissed in that the job may still be sending heartbeats but
 * showing no actual work progression.
 *
 * Used to identify jobs that are in infinite loops, deadlocked, or otherwise
 * unable to complete despite being technically alive. Provides information
 * about how long the job has been stuck and when the last heartbeat occurred.
 *
 * ```php
 * Event::listen(JobStuck::class, function ($event) {
 *     Log::error("Job stuck", [
 *         'supervision_id' => $event->supervisionId,
 *         'job_id' => $event->jobId,
 *         'stuck_duration' => $event->stuckDuration,
 *         'last_heartbeat' => $event->lastHeartbeat?->format('c'),
 *     ]);
 *
 *     // Trigger job termination after 30 minutes stuck
 *     if ($event->stuckDuration > 1800000) {
 *         JobTerminator::kill($event->jobId);
 *     }
 * });
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 * @psalm-immutable
 */
final readonly class JobStuck
{
    /**
     * Create a new job stuck event.
     *
     * @param string                 $supervisionId Unique identifier for the supervision session
     * @param string                 $jobId         The job instance identifier that is stuck
     * @param int                    $stuckDuration How long the job has been stuck in milliseconds
     * @param null|DateTimeImmutable $lastHeartbeat Timestamp of the last heartbeat received, if any
     */
    public function __construct(
        public string $supervisionId,
        public string $jobId,
        public int $stuckDuration,
        public ?DateTimeImmutable $lastHeartbeat,
    ) {}
}
