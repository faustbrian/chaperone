<?php declare(strict_types=1);

namespace Cline\Chaperone\Contracts;

interface ResourceMonitor
{
    public function checkMemory(string $jobId): array;

    public function checkCpu(string $jobId): array;

    public function checkDisk(string $jobId): array;

    public function isWithinLimits(string $jobId): bool;

    public function getCurrentUsage(string $jobId): array;
}
