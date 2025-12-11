<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Chaperone\Concerns\Supervised;
use Cline\Chaperone\Facades\Chaperone;
use Illuminate\Contracts\Queue\ShouldQueue;

it('can supervise a job with heartbeats', function (): void {
    $job = new class() implements ShouldQueue
    {
        use Supervised;

        public function handle(): void
        {
            $this->heartbeat(['test' => 'data']);
        }
    };

    $job->handle();

    expect($job->getSupervisionId())->toBeString();
});

it('can check circuit breaker state', function (): void {
    $breaker = Chaperone::circuitBreaker('test-service');

    expect($breaker->isClosed())->toBeTrue();
    expect($breaker->isOpen())->toBeFalse();
});

it('can get health status information', function (): void {
    $health = Chaperone::getHealth('test-supervision-id');

    expect($health)->toBeArray();
    expect($health['status'])->toBeString();
});
