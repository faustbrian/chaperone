<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Chaperone\Events;

/**
 * Event dispatched when a heartbeat is successfully received.
 *
 * Fired each time a supervised job sends a heartbeat signal, confirming
 * the job is still active and progressing. Includes optional metadata
 * from the job about its current state or progress.
 *
 * Used for monitoring job health, updating last-seen timestamps, and
 * tracking job progress through custom metadata attached to heartbeats.
 *
 * ```php
 * Event::listen(HeartbeatReceived::class, function ($event) {
 *     Cache::put("heartbeat:{$event->supervisionId}", now(), 300);
 *
 *     if (isset($event->metadata['progress'])) {
 *         Metrics::gauge('job.progress', $event->metadata['progress']);
 *     }
 * });
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 */
final readonly class HeartbeatReceived
{
    /**
     * Create a new heartbeat received event.
     *
     * @param string              $supervisionId Unique identifier for the supervision session
     * @param string              $heartbeatId   Unique identifier for this specific heartbeat
     * @param array<string,mixed> $metadata      Optional metadata from the job about current state
     */
    public function __construct(
        public string $supervisionId,
        public string $heartbeatId,
        public array $metadata,
    ) {}
}
