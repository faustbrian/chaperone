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
interface HealthMonitor
{
    public function isHealthy(string $jobId): bool;

    public function markHealthy(string $jobId): void;

    public function markUnhealthy(string $jobId, string $reason): void;

    public function getHealth(string $jobId): array;
}
