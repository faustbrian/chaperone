<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Chaperone\WorkerPools;

use Illuminate\Support\Collection;

/**
 * @author Brian Faust <brian@cline.sh>
 */
final class WorkerPoolRegistry
{
    private Collection $pools;

    public function __construct()
    {
        $this->pools = new Collection();
    }

    public function register(string $name, WorkerPoolSupervisor $pool): void
    {
        $this->pools->put($name, $pool);
    }

    public function get(string $name): ?WorkerPoolSupervisor
    {
        return $this->pools->get($name);
    }

    public function has(string $name): bool
    {
        return $this->pools->has($name);
    }

    public function all(): Collection
    {
        return $this->pools;
    }

    public function stop(string $name): void
    {
        $pool = $this->get($name);

        if (!$pool instanceof WorkerPoolSupervisor) {
            return;
        }

        $pool->stop();
        $this->pools->forget($name);
    }

    public function stopAll(): void
    {
        $this->pools->each(fn (WorkerPoolSupervisor $pool) => $pool->stop());
        $this->pools = new Collection();
    }
}
