<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Chaperone\Deployment;

use Cline\Chaperone\Events\DeploymentCompleted;
use Cline\Chaperone\Events\DeploymentStarted;
use Cline\Chaperone\Events\DeploymentTimedOut;
use Illuminate\Support\Facades\Event;

use function now;

/**
 * @author Brian Faust <brian@cline.sh>
 */
final class DeploymentCoordinator
{
    private array $queues = [];

    private int $timeout = 300;

    private bool $shouldCancel = false;

    private mixed $onTimeoutCallback = null;

    private readonly QueueDrainer $drainer;

    private readonly JobWaiter $waiter;

    public function __construct()
    {
        $this->drainer = new QueueDrainer();
        $this->waiter = new JobWaiter();
    }

    public function drainQueues(array $queues): self
    {
        $this->queues = $queues;

        return $this;
    }

    public function waitForCompletion(int $timeout): self
    {
        $this->timeout = $timeout;

        return $this;
    }

    public function cancelLongRunning(): self
    {
        $this->shouldCancel = true;

        return $this;
    }

    public function onTimeout(callable $callback): self
    {
        $this->onTimeoutCallback = $callback;

        return $this;
    }

    public function execute(): bool
    {
        Event::dispatch(
            new DeploymentStarted($this->queues, now())
        );

        // Drain queues
        $this->drainer->drain($this->queues);

        // Wait for jobs to complete
        $completed = $this->waiter->waitForJobs($this->queues, $this->timeout);

        if (!$completed) {
            // Timeout occurred
            $remainingJobs = $this->waiter->getRunningJobs();

            if ($this->onTimeoutCallback) {
                ($this->onTimeoutCallback)($remainingJobs);
            }

            Event::dispatch(
                new DeploymentTimedOut(
                    $this->queues,
                    $this->timeout,
                    $remainingJobs->count(),
                )
            );

            if ($this->shouldCancel) {
                // Cancel remaining jobs
                $remainingJobs->each(fn ($job) => $job->update(['failed_at' => now()]));

                Event::dispatch(
                    new DeploymentCompleted(
                        $this->queues,
                        now(),
                        $remainingJobs->count(),
                    )
                );

                return true;
            }

            return false;
        }

        Event::dispatch(
            new DeploymentCompleted($this->queues, now(), 0)
        );

        return true;
    }
}
