<?php declare(strict_types=1);

namespace Cline\Chaperone\Contracts;

interface SupervisionStrategy
{
    public function supervise(string $jobClass): void;

    public function onStuck(callable $callback): self;

    public function onTimeout(callable $callback): self;

    public function onFailure(callable $callback): self;

    public function onMemoryExceeded(callable $callback): self;

    public function onCpuExceeded(callable $callback): self;
}
