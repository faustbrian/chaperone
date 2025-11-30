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
 * Event dispatched when an expected heartbeat is not received.
 *
 * Fired when a supervised job fails to send a heartbeat within the expected
 * interval. Indicates the job may be stuck, crashed, or experiencing issues
 * preventing it from reporting its status.
 *
 * Used to trigger alerts, initiate recovery procedures, or escalate monitoring
 * when jobs become unresponsive. Provides timing information to determine
 * severity of the missed heartbeat.
 *
 * ```php
 * Event::listen(HeartbeatMissed::class, function ($event) {
 *     if ($event->missedDuration > 300000) { // 5 minutes
 *         Alert::critical("Job {$event->supervisionId} unresponsive for 5+ minutes");
 *     }
 *
 *     Log::warning("Missed heartbeat", [
 *         'supervision_id' => $event->supervisionId,
 *         'expected_at' => $event->expectedAt->format('c'),
 *         'missed_duration' => $event->missedDuration,
 *     ]);
 * });
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 */
final readonly class HeartbeatMissed
{
    /**
     * Create a new heartbeat missed event.
     *
     * @param string            $supervisionId  Unique identifier for the supervision session
     * @param DateTimeImmutable $expectedAt     When the heartbeat was expected to be received
     * @param int               $missedDuration How long past the expected time in milliseconds
     */
    public function __construct(
        public string $supervisionId,
        public DateTimeImmutable $expectedAt,
        public int $missedDuration,
    ) {}
}
