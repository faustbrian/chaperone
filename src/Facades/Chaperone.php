<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Chaperone\Facades;

use Cline\Chaperone\Chaperone as ChaperoneManager;
use Cline\Chaperone\Contracts\CircuitBreaker;
use Cline\Chaperone\Supervisors\JobSupervisor;
use Illuminate\Support\Facades\Facade;

/**
 * Facade for programmatic job supervision and monitoring.
 *
 * Provides a high-level API for supervising Laravel jobs, managing circuit breakers,
 * tracking heartbeats, and monitoring job health. Serves as the primary entry point
 * for all Chaperone functionality with a clean, expressive interface for configuring
 * supervision behavior, resource limits, and monitoring callbacks.
 *
 * @method static CircuitBreaker                                                                                                                     circuitBreaker(string $service)
 * @method static mixed                                                                                                                              dashboard()
 * @method static array{status: string, reason: null|string, updated_at: null|string, job_id: string, check_count: int, first_unhealthy_at?: string} getHealth(string $supervisionId)
 * @method static void                                                                                                                               heartbeat(string $supervisionId, array $metadata = [])
 * @method static bool                                                                                                                               isHealthy(string $supervisionId)
 * @method static mixed                                                                                                                              pool(string $name)
 * @method static mixed                                                                                                                              prepareForDeployment()
 * @method static JobSupervisor                                                                                                                      watch(string $jobClass)
 *
 * @author Brian Faust <brian@cline.sh>
 * @see ChaperoneManager
 */
final class Chaperone extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string The service container binding key for ChaperoneManager
     */
    protected static function getFacadeAccessor(): string
    {
        return ChaperoneManager::class;
    }
}
