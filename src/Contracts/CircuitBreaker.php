<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Chaperone\Contracts;

use Throwable;

/**
 * @author Brian Faust <brian@cline.sh>
 */
interface CircuitBreaker
{
    public function call(callable $callback): mixed;

    public function isOpen(): bool;

    public function isHalfOpen(): bool;

    public function isClosed(): bool;

    public function open(): void;

    public function close(): void;

    public function recordSuccess(): void;

    public function recordFailure(Throwable $exception): void;
}
