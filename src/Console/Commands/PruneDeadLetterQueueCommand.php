<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Chaperone\Console\Commands;

use Cline\Chaperone\DeadLetterQueue\DeadLetterQueueManager;
use Illuminate\Console\Command;

use function sprintf;

/**
 * Artisan command to prune old dead letter queue entries.
 *
 * Removes dead letter queue entries that have exceeded the configured retention period,
 * helping maintain database hygiene and prevent unbounded growth. Supports custom
 * retention periods via command option or uses configured default.
 *
 * ```bash
 * # Prune entries using configured retention period (default: 30 days)
 * php artisan chaperone:prune-dead-letters
 *
 * # Prune entries older than 60 days
 * php artisan chaperone:prune-dead-letters --days=60
 *
 * # Prune entries older than 7 days (weekly cleanup)
 * php artisan chaperone:prune-dead-letters --days=7
 * ```
 *
 * This command can be scheduled in your application's kernel to run automatically:
 *
 * ```php
 * // app/Console/Kernel.php
 * protected function schedule(Schedule $schedule)
 * {
 *     $schedule->command('chaperone:prune-dead-letters')
 *              ->daily()
 *              ->at('02:00');
 * }
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class PruneDeadLetterQueueCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * Accepts an optional --days flag to override the configured retention period.
     * If not specified, uses the retention period from chaperone.dead_letter_queue.retention_period.
     *
     * @var string
     */
    protected $signature = 'chaperone:prune-dead-letters
                            {--days= : Number of days to retain entries (overrides config)}';

    /**
     * The console command description shown in artisan list output.
     *
     * @var string
     */
    protected $description = 'Prune dead letter queue entries older than the retention period';

    /**
     * Execute the console command to prune dead letter entries.
     *
     * Deletes entries older than the specified retention period and displays
     * a summary of how many entries were removed. Uses configured retention
     * period if no custom value is provided via --days option.
     *
     * @param DeadLetterQueueManager $deadLetterQueueManager Service for managing dead letter queue
     *
     * @return int Command exit code: SUCCESS (0) if pruning completed, FAILURE (1) on error
     */
    public function handle(DeadLetterQueueManager $deadLetterQueueManager): int
    {
        $this->components->info('Pruning dead letter queue entries...');
        $this->newLine();

        $days = $this->option('days');
        $retentionDays = $days !== null ? (int) $days : null;

        if ($retentionDays !== null) {
            $this->components->info(sprintf('Using custom retention period: %d days', $retentionDays));
        } else {
            $configuredDays = (int) config('chaperone.dead_letter_queue.retention_period', 30);
            $this->components->info(sprintf('Using configured retention period: %d days', $configuredDays));
            $retentionDays = $configuredDays;
        }

        if ($retentionDays === 0) {
            $this->components->warn('Retention period is set to 0 (keep indefinitely). No entries will be pruned.');

            return self::SUCCESS;
        }

        $this->newLine();

        try {
            $deletedCount = $deadLetterQueueManager->prune($retentionDays);

            if ($deletedCount === 0) {
                $this->components->info('No entries found to prune.');
            } else {
                $this->components->success(sprintf(
                    'Successfully pruned %d dead letter queue %s older than %d days.',
                    $deletedCount,
                    $deletedCount === 1 ? 'entry' : 'entries',
                    $retentionDays,
                ));
            }

            // Display current queue statistics
            $this->newLine();
            $remainingCount = $deadLetterQueueManager->count();
            $this->components->twoColumnDetail(
                'Remaining entries in dead letter queue',
                sprintf('<fg=gray>%d</>', $remainingCount),
            );

            return self::SUCCESS;
        } catch (\Throwable $throwable) {
            $this->components->error('Failed to prune dead letter queue entries.');
            $this->components->error($throwable->getMessage());

            return self::FAILURE;
        }
    }
}
