<?php declare(strict_types=1);

namespace Cline\Chaperone\Contracts;

interface HealthMonitor
{
    public function isHealthy(string $jobId): bool;

    public function markHealthy(string $jobId): void;

    public function markUnhealthy(string $jobId, string $reason): void;

    public function getHealth(string $jobId): array;
}
