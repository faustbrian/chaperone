<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Chaperone\Enums;

/**
 * Supervised job status enumeration.
 *
 * Represents the lifecycle states of a supervised job from start to completion,
 * enabling tracking of job execution status throughout its lifetime.
 *
 * @author Brian Faust <brian@cline.sh>
 */
enum SupervisedJobStatus: string
{
    /**
     * Job is currently running.
     *
     * The job has started execution and is actively being supervised.
     * Heartbeats should be recorded regularly while in this state.
     */
    case Running = 'running';

    /**
     * Job has completed successfully.
     *
     * The job finished execution without errors and achieved its goal.
     */
    case Completed = 'completed';

    /**
     * Job has failed.
     *
     * The job encountered an error and could not complete successfully.
     * Error details should be available in associated records.
     */
    case Failed = 'failed';

    /**
     * Job has stalled or stopped responding.
     *
     * The job has not sent heartbeats within the expected interval,
     * indicating it may have crashed, hung, or lost connection.
     */
    case Stalled = 'stalled';
}
