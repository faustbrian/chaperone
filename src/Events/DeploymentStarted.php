<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Chaperone\Events;

use Illuminate\Support\Carbon;

/**
 * Event dispatched when a deployment process begins.
 *
 * Fired when the deployment coordinator starts the graceful shutdown process,
 * signaling that queues are being drained and jobs should complete before
 * deployment proceeds.
 *
 * Used for logging deployment start times, notifying administrators, and
 * coordinating dependent systems that need to pause during deployments.
 *
 * ```php
 * Event::listen(DeploymentStarted::class, function ($event) {
 *     Log::info('Deployment started', [
 *         'queues' => $event->queues,
 *         'started_at' => $event->startedAt,
 *     ]);
 *
 *     // Notify deployment tracking systems
 *     DeploymentTracker::mark('shutdown_started');
 * });
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 * @psalm-immutable
 */
final readonly class DeploymentStarted
{
    /**
     * Create a new deployment started event.
     *
     * @param array<int, string> $queues    Queue names being drained for deployment
     * @param Carbon             $startedAt Timestamp when deployment process began
     */
    public function __construct(
        public array $queues,
        public Carbon $startedAt,
    ) {}
}
