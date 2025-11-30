<?php declare(strict_types=1);

namespace Cline\Chaperone\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

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
