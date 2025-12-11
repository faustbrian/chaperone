<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Chaperone\Console\Commands;

use Cline\Chaperone\Deployment\DeploymentCoordinator;
use Cline\Chaperone\Queue\QueueFilter;
use Illuminate\Console\Command;
use Illuminate\Support\Sleep;

use function implode;
use function range;
use function sprintf;

/**
 * @author Brian Faust <brian@cline.sh>
 */
final class PrepareDeploymentCommand extends Command
{
    protected $signature = 'chaperone:prepare-deployment
                            {--queues=* : Queues to drain (default: all supervised)}
                            {--timeout=300 : Timeout in seconds}
                            {--cancel : Cancel long-running jobs after timeout}';

    protected $description = 'Prepare for deployment by draining queues and waiting for jobs';

    public function handle(QueueFilter $filter): int
    {
        $queues = $this->option('queues') ?: $filter->getSupervisedQueues();
        $timeout = (int) $this->option('timeout');

        if (empty($queues)) {
            $this->error('No queues to drain. Configure supervised_queues in config/chaperone.php');

            return self::FAILURE;
        }

        $this->info('Preparing for deployment...');
        $this->line('Queues: '.implode(', ', $queues));
        $this->line(sprintf('Timeout: %ds', $timeout));

        $coordinator = new DeploymentCoordinator();
        $coordinator->drainQueues($queues)
            ->waitForCompletion($timeout);

        if ($this->option('cancel')) {
            $coordinator->cancelLongRunning();
        }

        $coordinator->onTimeout(function ($jobs): void {
            $this->warn(sprintf('Deployment timed out with %s jobs remaining', $jobs->count()));
            $this->table(
                ['ID', 'Class', 'Queue', 'Started'],
                $jobs->map(fn ($job): array => [
                    $job->id,
                    $job->job_class,
                    $job->queue_name,
                    $job->started_at?->diffForHumans(),
                ]),
            );
        });

        $this->withProgressBar(
            range(1, $timeout),
            function (): void {
                Sleep::sleep(1);
            },
        );

        $this->newLine(2);

        $success = $coordinator->execute();

        if ($success) {
            $this->info('✓ Deployment preparation complete');

            return self::SUCCESS;
        }

        $this->error('✗ Deployment preparation failed or timed out');

        return self::FAILURE;
    }
}
