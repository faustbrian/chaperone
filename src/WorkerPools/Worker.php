<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Chaperone\WorkerPools;

use DateTimeInterface;
use Illuminate\Support\Facades\Date;

use const SIGTERM;

use function getmypid;
use function memory_get_usage;
use function posix_kill;

/**
 * @author Brian Faust <brian@cline.sh>
 */
final class Worker
{
    public int $pid = 0;

    public string $status = 'running';

    public DateTimeInterface $startedAt;

    public ?DateTimeInterface $lastHealthCheck = null;

    private $healthCheckCallback;

    public function __construct(
        public readonly string $id,
        public readonly string $queueName,
        ?callable $healthCheckCallback = null,
    ) {
        // Will be set when process starts
        $this->startedAt = Date::now();
        $this->healthCheckCallback = $healthCheckCallback;
    }

    public function start(): void
    {
        // In real implementation, this would spawn a Laravel queue worker process
        // For now, simulate it by getting current PID
        $this->pid = getmypid() ?: 0;
        $this->status = 'running';
    }

    public function isResponsive(): bool
    {
        if ($this->status === 'stopped' || $this->status === 'crashed') {
            return false;
        }

        // Check if process is still running
        return $this->pid && posix_kill($this->pid, 0);
    }

    public function memoryUsage(): int
    {
        return (int) (memory_get_usage(true) / 1_024 / 1_024); // MB
    }

    public function restart(): void
    {
        $this->kill();
        $this->pid = getmypid() ?: 0;
        $this->status = 'running';
        $this->startedAt = Date::now();
    }

    public function kill(): void
    {
        if (!$this->pid || !posix_kill($this->pid, SIGTERM)) {
            return;
        }

        $this->status = 'stopped';
    }

    public function healthCheck(): bool
    {
        $this->lastHealthCheck = Date::now();

        // Use custom health check if provided
        if ($this->healthCheckCallback) {
            return ($this->healthCheckCallback)($this);
        }

        // Default health check
        if (!$this->isResponsive()) {
            $this->status = 'crashed';

            return false;
        }

        return $this->memoryUsage() <= 512;
    }
}
