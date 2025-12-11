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
interface ResourceMonitor
{
    public function checkMemory(string $jobId): array;

    public function checkCpu(string $jobId): array;

    public function checkDisk(string $jobId): array;

    public function isWithinLimits(string $jobId): bool;

    public function getCurrentUsage(string $jobId): array;
}
