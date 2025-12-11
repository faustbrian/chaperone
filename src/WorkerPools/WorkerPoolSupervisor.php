<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Chaperone\WorkerPools;

use Illuminate\Support\Collection;
use Illuminate\Support\Sleep;
use InvalidArgumentException;
use RuntimeException;

use function sprintf;
use function throw_if;
use function uniqid;

/**
 * Supervises a pool of queue workers with health monitoring and automatic restart.
 *
 * Maintains a pool of worker processes, monitors their health, and automatically
 * restarts unhealthy or crashed workers to ensure queue processing continues
 * reliably. Provides hooks for custom health checks and event callbacks.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class WorkerPoolSupervisor
{
    /** @var Collection<int, Worker> Collection of workers in this pool */
    private Collection $workers;

    /** @var int Number of workers to maintain */
    private int $workerCount = 1;

    /** @var string Queue name to supervise */
    private string $queueName = 'default';

    /** @var null|callable Health check callback: fn(Worker): bool */
    private $healthCheckCallback;

    /** @var null|callable Unhealthy worker callback: fn(Worker): void */
    private $unhealthyCallback;

    /** @var null|callable Worker crash callback: fn(Worker): void */
    private $crashCallback;

    /** @var bool Whether supervision is currently running */
    private bool $supervising = false;

    /**
     * Create a new worker pool supervisor.
     *
     * @param string $name Unique name for this worker pool
     */
    public function __construct(
        private readonly string $name,
    ) {
        $this->workers = new Collection();
    }

    /**
     * Set the number of workers to maintain in the pool.
     *
     * @param  int  $count Number of workers (must be >= 1)
     * @return self Fluent interface
     */
    public function workers(int $count): self
    {
        throw_if($count < 1, InvalidArgumentException::class, 'Worker count must be at least 1');

        $this->workerCount = $count;

        return $this;
    }

    /**
     * Set the queue name for workers to process.
     *
     * @param  string $name Laravel queue name
     * @return self   Fluent interface
     */
    public function queue(string $name): self
    {
        $this->queueName = $name;

        return $this;
    }

    /**
     * Register a custom health check callback.
     *
     * The callback receives a Worker instance and should return true if healthy,
     * false if unhealthy. If not provided, default health checks are used
     * (process responsive and memory within limits).
     *
     * Example:
     * ```php
     * ->withHealthCheck(function ($worker) {
     *     return $worker->isResponsive() && $worker->memoryUsage() < 512;
     * })
     * ```
     *
     * @param  callable $callback Health check function: fn(Worker): bool
     * @return self     Fluent interface
     */
    public function withHealthCheck(callable $callback): self
    {
        $this->healthCheckCallback = $callback;

        return $this;
    }

    /**
     * Register a callback for unhealthy workers.
     *
     * Called when a worker fails health checks. The callback receives the
     * unhealthy Worker instance and can perform custom recovery actions.
     *
     * Example:
     * ```php
     * ->onUnhealthy(function ($worker) {
     *     Log::warning("Worker {$worker->id} unhealthy");
     *     $worker->restart();
     * })
     * ```
     *
     * @param  callable $callback Unhealthy handler: fn(Worker): void
     * @return self     Fluent interface
     */
    public function onUnhealthy(callable $callback): self
    {
        $this->unhealthyCallback = $callback;

        return $this;
    }

    /**
     * Register a callback for crashed workers.
     *
     * Called when a worker process crashes or terminates unexpectedly.
     * The callback receives the crashed Worker instance and can log,
     * alert, or perform cleanup actions.
     *
     * Example:
     * ```php
     * ->onCrash(function ($worker) {
     *     Log::error("Worker {$worker->id} crashed");
     *     Slack::alert("Worker pool {$this->name} worker crashed");
     * })
     * ```
     *
     * @param  callable $callback Crash handler: fn(Worker): void
     * @return self     Fluent interface
     */
    public function onCrash(callable $callback): self
    {
        $this->crashCallback = $callback;

        return $this;
    }

    /**
     * Start supervising the worker pool.
     *
     * Spawns the configured number of workers and begins monitoring their
     * health. Runs in a loop, checking worker status and restarting failed
     * workers as needed. This method blocks until supervision is stopped.
     *
     * @throws RuntimeException If supervision is already running
     */
    public function supervise(): void
    {
        if ($this->supervising) {
            throw new RuntimeException(sprintf('Pool %s is already supervising', $this->name));
        }

        $this->supervising = true;

        // Spawn initial workers
        for ($i = 0; $i < $this->workerCount; ++$i) {
            $this->spawnWorker();
        }

        // Supervision loop
        while ($this->supervising) {
            $this->checkWorkers();
            Sleep::usleep(1_000_000); // Check every second
        }
    }

    /**
     * Stop supervision and shut down all workers.
     *
     * Gracefully stops all worker processes and exits the supervision loop.
     * Workers are given time to finish their current jobs before being killed.
     */
    public function stop(): void
    {
        $this->supervising = false;

        foreach ($this->workers as $worker) {
            $worker->kill();
        }

        $this->workers = new Collection();
    }

    /**
     * Get current status of the worker pool.
     *
     * Returns an array containing pool name, total worker count, and detailed
     * status for each worker including their PID, status, uptime, and health.
     *
     * Example return:
     * ```php
     * [
     *     'name' => 'import-workers',
     *     'worker_count' => 5,
     *     'workers' => [
     *         [
     *             'id' => 'worker-1',
     *             'pid' => 12345,
     *             'status' => 'running',
     *             'started_at' => '2025-11-23 15:00:00',
     *             'memory_usage' => 256,
     *         ],
     *         // ... more workers
     *     ],
     * ]
     * ```
     *
     * @return array{name: string, worker_count: int, workers: array<int, array>} Pool status
     */
    public function getStatus(): array
    {
        return [
            'name' => $this->name,
            'worker_count' => $this->workerCount,
            'workers' => $this->workers->map(fn (Worker $worker): array => [
                'id' => $worker->id,
                'pid' => $worker->pid,
                'status' => $worker->status,
                'started_at' => $worker->startedAt?->toDateTimeString(),
                'last_health_check' => $worker->lastHealthCheck?->toDateTimeString(),
                'memory_usage' => $worker->memoryUsage(),
            ])->values()->all(),
        ];
    }

    /**
     * Get the pool name.
     *
     * @return string Pool identifier
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Spawn a new worker process.
     *
     * Creates a new Worker instance, starts its Laravel queue worker process,
     * and adds it to the pool's worker collection.
     */
    private function spawnWorker(): void
    {
        $worker = new Worker(
            id: $this->name.'-worker-'.uniqid(),
            queueName: $this->queueName,
            healthCheckCallback: $this->healthCheckCallback,
        );

        $worker->start();

        $this->workers->push($worker);
    }

    /**
     * Check health of all workers and handle failures.
     *
     * Iterates through all workers, performs health checks, and takes action
     * on unhealthy or crashed workers. Crashed workers are automatically
     * replaced with new instances to maintain the configured worker count.
     */
    private function checkWorkers(): void
    {
        foreach ($this->workers as $index => $worker) {
            // Check if worker crashed
            if ($worker->status === 'crashed' || !$worker->isResponsive()) {
                $worker->status = 'crashed';

                if ($this->crashCallback) {
                    ($this->crashCallback)($worker);
                }

                // Remove crashed worker and spawn replacement
                $this->workers->forget($index);
                $this->spawnWorker();

                continue;
            }

            // Run health check
            if ($worker->healthCheck()) {
                continue;
            }

            if ($this->unhealthyCallback) {
                ($this->unhealthyCallback)($worker);
            } else {
                // Default action: restart unhealthy worker
                $worker->restart();
            }
        }
    }
}
