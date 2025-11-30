<?php declare(strict_types=1);

namespace Cline\Chaperone\Contracts;

interface CircuitBreaker
{
    public function call(callable $callback): mixed;

    public function isOpen(): bool;

    public function isHalfOpen(): bool;

    public function isClosed(): bool;

    public function open(): void;

    public function close(): void;

    public function recordSuccess(): void;

    public function recordFailure(\Throwable $exception): void;
}
