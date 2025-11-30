<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Chaperone\Enums;

/**
 * Resource violation type enumeration.
 *
 * Defines the types of resource violations that can be detected and recorded
 * during job supervision, enabling monitoring and enforcement of resource limits.
 *
 * @author Brian Faust <brian@cline.sh>
 */
enum ResourceViolationType: string
{
    /**
     * Memory usage exceeded configured limit.
     *
     * The job's memory consumption has exceeded the maximum allowed threshold.
     */
    case Memory = 'memory';

    /**
     * CPU usage exceeded configured limit.
     *
     * The job's CPU utilization has exceeded the maximum allowed percentage.
     */
    case Cpu = 'cpu';

    /**
     * Execution time exceeded configured limit.
     *
     * The job has been running longer than the maximum allowed duration.
     */
    case Time = 'time';

    /**
     * Disk usage exceeded configured limit.
     *
     * The job's disk I/O or storage usage has exceeded allowed thresholds.
     */
    case Disk = 'disk';
}
