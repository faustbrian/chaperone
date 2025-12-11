<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Chaperone\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * @author Brian Faust <brian@cline.sh>
 * @psalm-immutable
 */
final readonly class DeploymentTimedOut
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public array $queues,
        public int $timeout,
        public int $remainingJobCount,
    ) {}
}
