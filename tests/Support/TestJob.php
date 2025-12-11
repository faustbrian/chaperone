<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Support;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Test job class for unit testing.
 *
 * Simple job implementation used for testing dead letter queue
 * and supervision functionality without executing actual business logic.
 * @author Brian Faust <brian@cline.sh>
 */
final class TestJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly mixed $data = null,
    ) {}

    public function handle(): void
    {
        // Test job - no actual handling needed
    }
}
