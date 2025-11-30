<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Chaperone\Queue;

use function array_filter;
use function config;
use function in_array;

/**
 * Queue filtering logic for supervised/excluded queues enforcement.
 *
 * Determines which queues should be supervised based on configuration settings.
 * Supports both allowlist (supervised_queues) and denylist (excluded_queues) patterns.
 *
 * Filtering logic:
 * - If supervised_queues is empty, supervise all queues except those in excluded_queues
 * - If supervised_queues has values, only supervise those queues
 * - excluded_queues always takes precedence over supervised_queues
 *
 * @author Brian Faust <brian@cline.sh>
 */
final readonly class QueueFilter
{
    /**
     * Determine if a queue should be supervised.
     *
     * @param string $queueName The name of the queue to check
     *
     * @return bool True if the queue should be supervised, false otherwise
     */
    public function shouldSupervise(string $queueName): bool
    {
        // Always exclude if explicitly in excluded list
        if (in_array($queueName, $this->getExcludedQueues(), true)) {
            return false;
        }

        $supervisedQueues = $this->getSupervisedQueues();

        // If no specific queues configured, supervise all (except excluded)
        if ($supervisedQueues === []) {
            return true;
        }

        // Only supervise if explicitly in supervised list
        return in_array($queueName, $supervisedQueues, true);
    }

    /**
     * Get the list of queues that should be supervised.
     *
     * Returns array of queue names from configuration. Empty array means
     * all queues should be supervised (except excluded ones).
     *
     * @return array<int, string> Array of queue names to supervise
     */
    public function getSupervisedQueues(): array
    {
        /** @var array<int, string> $queues */
        $queues = config('chaperone.queue.supervised_queues', []);

        // Filter out empty strings from config
        return array_filter($queues, static fn (string $queue): bool => $queue !== '');
    }

    /**
     * Get the list of queues that should be excluded from supervision.
     *
     * Returns array of queue names that should never be supervised,
     * regardless of supervised_queues configuration.
     *
     * @return array<int, string> Array of queue names to exclude
     */
    public function getExcludedQueues(): array
    {
        /** @var array<int, string> $queues */
        $queues = config('chaperone.queue.excluded_queues', []);

        // Filter out empty strings from config
        return array_filter($queues, static fn (string $queue): bool => $queue !== '');
    }
}
