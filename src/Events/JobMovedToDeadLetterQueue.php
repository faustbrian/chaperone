<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Chaperone\Events;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * Event dispatched when a job is moved to the dead letter queue.
 *
 * Fired when a supervised job has permanently failed after exceeding retry attempts
 * or encountering an unrecoverable error. Provides complete failure context including
 * the exception details and timing for alerting and monitoring purposes.
 *
 * This event indicates that automatic recovery has been exhausted and manual
 * intervention may be required. Listen to this event to trigger alerts, logging,
 * or notification workflows for critical job failures.
 *
 * ```php
 * Event::listen(JobMovedToDeadLetterQueue::class, function ($event) {
 *     Log::critical("Job moved to dead letter queue: {$event->jobClass}", [
 *         'supervision_id' => $event->supervisionId,
 *         'exception' => $event->exception->getMessage(),
 *         'failed_at' => $event->failedAt,
 *     ]);
 *
 *     // Send alert to operations team
 *     Notification::send(
 *         User::where('role', 'ops')->get(),
 *         new JobPermanentlyFailedNotification($event)
 *     );
 * });
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 * @psalm-immutable
 */
final readonly class JobMovedToDeadLetterQueue
{
    /**
     * Create a new job moved to dead letter queue event.
     *
     * @param string    $supervisionId Unique identifier for the supervision session
     * @param string    $jobClass      Fully qualified class name of the failed job
     * @param Throwable $exception     The exception that caused permanent failure
     * @param Carbon    $failedAt      Timestamp when job was moved to dead letter queue
     */
    public function __construct(
        public string $supervisionId,
        public string $jobClass,
        public Throwable $exception,
        public Carbon $failedAt,
    ) {}
}
