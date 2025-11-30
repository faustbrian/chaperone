<?php declare(strict_types=1);

namespace Cline\Chaperone\Events;

use Carbon\Carbon;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final readonly class DeploymentCompleted
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public array $queues,
        public Carbon $completedAt,
        public int $cancelledJobCount,
    ) {}
}
