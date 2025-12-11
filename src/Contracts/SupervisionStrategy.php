<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Chaperone\Contracts;

/**
 * @author Brian Faust <brian@cline.sh>
 */
interface SupervisionStrategy
{
    public function supervise(string $jobClass): void;

    public function onStuck(callable $callback): self;

    public function onTimeout(callable $callback): self;

    public function onFailure(callable $callback): self;

    public function onMemoryExceeded(callable $callback): self;

    public function onCpuExceeded(callable $callback): self;
}
