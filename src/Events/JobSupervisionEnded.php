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
 * Event dispatched when job supervision completes.
 *
 * Fired when supervision session ends, whether the job completed successfully,
 * failed, or was terminated. Provides completion timestamp and total duration
 * for metrics and cleanup operations.
 *
 * Pairs with JobSupervisionStarted to bracket supervision lifecycle. Dispatched
 * after all supervision activities (heartbeat tracking, resource monitoring)
 * have ceased and final state has been recorded.
 *
 * ```php
 * Event::listen(JobSupervisionEnded::class, function ($event) {
 *     Metrics::timing('supervision.duration', $event->duration);
 *     Log::info("Supervision {$event->supervisionId} ended after {$event->duration}ms");
 * });
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 */
final readonly class JobSupervisionEnded
{
    /**
     * Create a new job supervision ended event.
     *
     * @param string            $supervisionId Unique identifier for the supervision session
     * @param string            $jobId         The job instance identifier that was supervised
     * @param DateTimeImmutable $completedAt   Timestamp when supervision ended
     * @param int               $duration      Total supervision duration in milliseconds
     */
    public function __construct(
        public string $supervisionId,
        public string $jobId,
        public DateTimeImmutable $completedAt,
        public int $duration,
    ) {}
}
