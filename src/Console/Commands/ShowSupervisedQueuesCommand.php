<?php declare(strict_types=1);

namespace Cline\Chaperone\Console\Commands;

use Cline\Chaperone\Queue\QueueFilter;
use Illuminate\Console\Command;

final class ShowSupervisedQueuesCommand extends Command
{
    protected $signature = 'chaperone:queues
                            {--format=table : Output format (table, json, list)}';

    protected $description = 'Show which queues are supervised';

    public function handle(QueueFilter $filter): int
    {
        $supervised = $filter->getSupervisedQueues();
        $excluded = $filter->getExcludedQueues();

        if ($this->option('format') === 'json') {
            $this->line(json_encode([
                'supervised' => $supervised,
                'excluded' => $excluded,
                'mode' => $supervised === [] ? 'all_except_excluded' : 'allowlist',
            ], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        if ($this->option('format') === 'list') {
            foreach ($supervised as $queue) {
                $this->line($queue);
            }

            return self::SUCCESS;
        }

        $this->info('Supervised Queues Configuration');
        $this->newLine();

        if ($supervised === []) {
            $this->line('<fg=yellow>Mode:</> All queues (except excluded)');
        } else {
            $this->line('<fg=yellow>Mode:</> Allowlist');
            $this->newLine();
            $this->line('<fg=green>Supervised Queues:</>');
            foreach ($supervised as $queue) {
                $this->line('  • ' . $queue);
            }
        }

        if ($excluded !== []) {
            $this->newLine();
            $this->line('<fg=red>Excluded Queues:</>');
            foreach ($excluded as $queue) {
                $this->line('  • ' . $queue);
            }
        }

        $this->newLine();
        $this->line('Example usage:');
        $this->line('  $filter->shouldSupervise("default") => '.($filter->shouldSupervise('default') ? '<fg=green>true</>' : '<fg=red>false</>'));

        return self::SUCCESS;
    }
}
