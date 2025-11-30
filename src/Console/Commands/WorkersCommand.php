<?php declare(strict_types=1);

namespace Cline\Chaperone\Console\Commands;

use Cline\Chaperone\WorkerPools\WorkerPoolRegistry;
use Illuminate\Console\Command;

final class WorkersCommand extends Command
{
    protected $signature = 'chaperone:workers
                            {pool? : Show specific worker pool}
                            {--format=table : Output format (table, json)}';

    protected $description = 'Display worker pool status and health';

    public function handle(WorkerPoolRegistry $registry): int
    {
        $poolName = $this->argument('pool');

        if ($poolName) {
            return $this->showPool($registry, $poolName);
        }

        return $this->showAllPools($registry);
    }

    private function showPool(WorkerPoolRegistry $registry, string $name): int
    {
        $pool = $registry->get($name);
        $status = $pool->getStatus();

        if ($this->option('format') === 'json') {
            $this->line(json_encode($status, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info('Worker Pool: ' . $status['name']);
        $this->table(
            ['ID', 'PID', 'Status', 'Started At', 'Memory (MB)'],
            collect($status['workers'])->map(fn ($worker): array => [
                $worker['id'],
                $worker['pid'] ?? 'N/A',
                $worker['status'],
                $worker['started_at'] ?? 'N/A',
                $worker['memory_usage'] ?? 'N/A',
            ]),
        );

        return self::SUCCESS;
    }

    private function showAllPools(WorkerPoolRegistry $registry): int
    {
        $pools = $registry->all();

        if ($pools->isEmpty()) {
            $this->warn('No worker pools registered');

            return self::SUCCESS;
        }

        if ($this->option('format') === 'json') {
            $this->line(json_encode($pools->map(fn ($pool) => $pool->getStatus()), JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        foreach ($pools as $pool) {
            $status = $pool->getStatus();
            $this->info(sprintf('Pool: %s (%s workers)', $status['name'], $status['worker_count']));
        }

        return self::SUCCESS;
    }
}
