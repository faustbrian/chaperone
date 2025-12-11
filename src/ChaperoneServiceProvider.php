<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Chaperone;

use Cline\Chaperone\Alerting\AlertDispatcher;
use Cline\Chaperone\CircuitBreakers\CircuitBreakerManager;
use Cline\Chaperone\CircuitBreakers\CircuitBreakerRegistry;
use Cline\Chaperone\Console\Commands\CircuitBreakerCommand;
use Cline\Chaperone\Console\Commands\HealthCommand;
use Cline\Chaperone\Console\Commands\MonitorCommand;
use Cline\Chaperone\Console\Commands\PrepareDeploymentCommand;
use Cline\Chaperone\Console\Commands\PruneDeadLetterQueueCommand;
use Cline\Chaperone\Console\Commands\ShowSupervisedQueuesCommand;
use Cline\Chaperone\Console\Commands\StuckJobsCommand;
use Cline\Chaperone\Console\Commands\TestAlertsCommand;
use Cline\Chaperone\Console\Commands\WorkersCommand;
use Cline\Chaperone\Contracts\CircuitBreaker;
use Cline\Chaperone\Contracts\HealthMonitor;
use Cline\Chaperone\Contracts\ResourceMonitor;
use Cline\Chaperone\Contracts\SupervisionStrategy;
use Cline\Chaperone\DeadLetterQueue\DeadLetterQueueManager;
use Cline\Chaperone\Observers\ChaperoneObserver;
use Cline\Chaperone\Queue\QueueFilter;
use Cline\Chaperone\Supervisors\HealthCheckManager;
use Cline\Chaperone\Supervisors\HeartbeatMonitor;
use Cline\Chaperone\Supervisors\JobSupervisor;
use Cline\Chaperone\Supervisors\ResourceLimitEnforcer;
use Cline\Chaperone\WorkerPools\WorkerPoolRegistry;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\ServiceProvider;
use Override;

use function config;
use function config_path;
use function database_path;

/**
 * Laravel service provider for the Chaperone supervision package.
 *
 * Bootstraps the Chaperone package by registering core services into the container,
 * binding supervision contracts to implementations, publishing configuration and migrations,
 * and registering Artisan commands. Provides the foundation for job supervision,
 * circuit breaker management, health monitoring, and resource limit enforcement.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class ChaperoneServiceProvider extends ServiceProvider
{
    /**
     * Register package services into the Laravel container.
     *
     * Merges package configuration with application config, registers singletons
     * for core services, binds contracts to their implementations, and registers
     * the main Chaperone manager as a singleton for programmatic access.
     */
    #[Override()]
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/chaperone.php',
            'chaperone',
        );

        // Register singletons for core services
        $this->app->singleton(CircuitBreakerRegistry::class);
        $this->app->singleton(HeartbeatMonitor::class);
        $this->app->singleton(HealthCheckManager::class);
        $this->app->singleton(ResourceLimitEnforcer::class);
        $this->app->singleton(DeadLetterQueueManager::class);
        $this->app->singleton(AlertDispatcher::class);
        $this->app->singleton(WorkerPoolRegistry::class);
        $this->app->singleton(QueueFilter::class);

        // Bind contracts to implementations
        $this->app->bind(CircuitBreaker::class, CircuitBreakerManager::class);
        $this->app->bind(ResourceMonitor::class, ResourceLimitEnforcer::class);
        $this->app->bind(HealthMonitor::class, HealthCheckManager::class);
        $this->app->bind(SupervisionStrategy::class, JobSupervisor::class);

        // Register main Chaperone manager
        $this->app->singleton(Chaperone::class);
    }

    /**
     * Bootstrap package services after container registration.
     *
     * Called after all service providers have been registered, ensuring safe access
     * to all container bindings. Initializes publishable resources for configuration
     * and migrations, and registers Artisan commands for supervision management.
     */
    public function boot(): void
    {
        $this->registerPublishing();
        $this->registerCommands();
        $this->registerObservers();
        $this->registerEventListeners();
    }

    /**
     * Register publishable package resources for console environments.
     *
     * Makes configuration files and database migrations available for publishing
     * to the application via `php artisan vendor:publish`. Only registers publishers
     * when running in console mode to avoid unnecessary overhead in HTTP and queue
     * worker contexts.
     *
     * Publishable resources:
     * - chaperone-config: Main configuration file to config/chaperone.php
     * - chaperone-migrations: Database migration with timestamped filename
     */
    private function registerPublishing(): void
    {
        if (!$this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/chaperone.php' => config_path('chaperone.php'),
        ], 'chaperone-config');

        $this->publishes([
            __DIR__.'/../database/migrations/create_chaperone_tables.php' => database_path('migrations/'.Date::now()->format('Y_m_d_His').'_create_chaperone_tables.php'),
        ], 'chaperone-migrations');
    }

    /**
     * Register package Artisan commands for console environments.
     *
     * Makes Chaperone management commands available to the application when running
     * in console mode. Provides the primary CLI interface for monitoring supervision
     * status, checking health, managing circuit breakers, and pruning dead letter queue.
     *
     * Registered commands:
     * - MonitorCommand: View supervision status and metrics
     * - HealthCommand: Check health status of supervised jobs
     * - CircuitBreakerCommand: Manage circuit breaker states
     * - StuckJobsCommand: Identify and handle stuck jobs
     * - PruneDeadLetterQueueCommand: Clean up old dead letter queue entries
     * - WorkersCommand: View worker pool status
     * - PrepareDeploymentCommand: Coordinate graceful deployments
     * - ShowSupervisedQueuesCommand: Display supervised queue configuration
     * - TestAlertsCommand: Test alert notifications
     */
    private function registerCommands(): void
    {
        if (!$this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            MonitorCommand::class,
            HealthCommand::class,
            CircuitBreakerCommand::class,
            StuckJobsCommand::class,
            PruneDeadLetterQueueCommand::class,
            WorkersCommand::class,
            PrepareDeploymentCommand::class,
            ShowSupervisedQueuesCommand::class,
            TestAlertsCommand::class,
        ]);
    }

    /**
     * Register model observers for automated supervision.
     */
    private function registerObservers(): void
    {
        $model = config('chaperone.models.supervised_job');
        $model::observe(ChaperoneObserver::class);
    }

    /**
     * Register event listeners for alerting.
     */
    private function registerEventListeners(): void
    {
        // Event listeners are registered in AlertDispatcher via constructor injection
        // The dispatcher automatically listens for supervision events when resolved
    }
}
