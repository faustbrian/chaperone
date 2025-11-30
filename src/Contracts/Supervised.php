<?php declare(strict_types=1);

namespace Cline\Chaperone\Contracts;

interface Supervised
{
    public function heartbeat(array $metadata = []): void;

    public function reportProgress(int $current, int $total, array $metadata = []): void;

    public function getSupervisionId(): string;
}
