<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Chaperone\Console\Commands;

use Illuminate\Support\Facades\Date;
use Cline\Chaperone\Database\Models\CircuitBreaker;
use Cline\Chaperone\Enums\CircuitBreakerState;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

use function sprintf;

/**
 * Artisan command to manage circuit breakers.
 *
 * Provides comprehensive circuit breaker management capabilities including viewing
 * current status, manually opening/closing circuits, and resetting failure counts.
 * Supports both individual service management and viewing all circuit breakers.
 *
 * ```bash
 * # Show status of all circuit breakers
 * php artisan chaperone:circuit-breaker --status
 *
 * # Show status of specific service
 * php artisan chaperone:circuit-breaker payment-gateway --status
 *
 * # Manually open a circuit
 * php artisan chaperone:circuit-breaker payment-gateway --open
 *
 * # Manually close a circuit
 * php artisan chaperone:circuit-breaker payment-gateway --close
 *
 * # Reset circuit to closed state with zero failures
 * php artisan chaperone:circuit-breaker payment-gateway --reset
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class CircuitBreakerCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * Accepts an optional service argument for targeting specific circuits,
     * and four optional flags for different operations:
     * - status: Show current status (default operation)
     * - open: Manually open the circuit
     * - close: Manually close the circuit
     * - reset: Reset the circuit to closed with zero failures
     *
     * @var string
     */
    protected $signature = 'chaperone:circuit-breaker
                            {service? : Service name (shows all if omitted)}
                            {--status : Show current status}
                            {--open : Manually open circuit}
                            {--close : Manually close circuit}
                            {--reset : Reset circuit to closed with zero failures}';

    /**
     * The console command description shown in artisan list output.
     *
     * @var string
     */
    protected $description = 'Manage circuit breakers for supervised jobs';

    /**
     * Execute the console command to manage circuit breakers.
     *
     * Routes to appropriate management method based on provided options. When no
     * specific action is requested, defaults to showing status. Validates that only
     * one action flag is provided at a time.
     *
     * @return int Command exit code: self::SUCCESS (0) if operation completes
     *             successfully, self::FAILURE (1) if validation fails or circuit
     *             breaker is not found
     */
    public function handle(): int
    {
        $service = $this->argument('service');
        $this->option('status');
        $openCircuit = (bool) $this->option('open');
        $closeCircuit = (bool) $this->option('close');
        $resetCircuit = (bool) $this->option('reset');

        // Count how many action flags are set
        $actionCount = (int) $openCircuit + (int) $closeCircuit + (int) $resetCircuit;

        if ($actionCount > 1) {
            $this->components->error('Please specify only one action: --open, --close, or --reset.');

            return self::FAILURE;
        }

        if ($openCircuit) {
            return $this->openCircuit((string) $service);
        }

        if ($closeCircuit) {
            return $this->closeCircuit((string) $service);
        }

        if ($resetCircuit) {
            return $this->resetCircuit((string) $service);
        }

        // Default to showing status
        return $this->displayStatus($service);
    }

    /**
     * Display circuit breaker status.
     *
     * Shows current state, failure counts, and timestamps for one or all circuit
     * breakers. When no service is specified, displays all circuit breakers in a table.
     *
     * @param mixed $service Optional service name to filter results
     *
     * @return int Command exit code: self::SUCCESS (0) if circuits are found,
     *             self::FAILURE (1) if specified service is not found
     */
    private function displayStatus(mixed $service): int
    {
        if ($service !== null) {
            return $this->displayServiceStatus((string) $service);
        }

        /** @var Collection<int, CircuitBreaker> $circuitBreakers */
        $circuitBreakers = CircuitBreaker::query()
            ->latest('updated_at')
            ->get();

        $this->components->info('Circuit Breaker Status');

        if ($circuitBreakers->isEmpty()) {
            $this->components->warn('No circuit breakers found.');

            return self::SUCCESS;
        }

        $this->table(
            ['Service', 'State', 'Failures', 'Last Failure', 'Last Success', 'Opened At'],
            $circuitBreakers->map(fn (CircuitBreaker $breaker): array => [
                $breaker->service_name,
                $this->formatState($breaker->state),
                $breaker->failure_count,
                $breaker->last_failure_at?->format('Y-m-d H:i:s') ?? 'N/A',
                $breaker->last_success_at?->format('Y-m-d H:i:s') ?? 'N/A',
                $breaker->opened_at?->format('Y-m-d H:i:s') ?? 'N/A',
            ])->all(),
        );

        $this->newLine();
        $this->components->info(sprintf('Total: %d circuit breaker(s)', $circuitBreakers->count()));

        // Show summary by state
        $summary = $circuitBreakers->groupBy(fn (CircuitBreaker $breaker): string => $breaker->state->value);

        $this->newLine();
        $this->components->info('Summary by State:');

        foreach ([CircuitBreakerState::Closed, CircuitBreakerState::HalfOpen, CircuitBreakerState::Open] as $state) {
            $count = $summary->get($state->value)?->count() ?? 0;
            $this->components->twoColumnDetail(
                sprintf('%s circuits', $state->label()),
                sprintf('<fg=%s>%d</>', $state->color(), $count),
            );
        }

        return self::SUCCESS;
    }

    /**
     * Display status for a specific service circuit breaker.
     *
     * Shows detailed information about a single circuit breaker including
     * state, failure metrics, and recent state changes.
     *
     * @param string $serviceName The service name to look up
     *
     * @return int Command exit code: self::SUCCESS (0) if circuit is found,
     *             self::FAILURE (1) if not found
     */
    private function displayServiceStatus(string $serviceName): int
    {
        /** @var null|CircuitBreaker $breaker */
        $breaker = CircuitBreaker::query()
            ->where('service_name', $serviceName)
            ->first();

        if ($breaker === null) {
            $this->components->error(sprintf('Circuit breaker for service "%s" not found.', $serviceName));

            return self::FAILURE;
        }

        $this->components->info(sprintf('Circuit Breaker Status: %s', $breaker->service_name));
        $this->newLine();

        $this->components->twoColumnDetail('State', $this->formatState($breaker->state));
        $this->components->twoColumnDetail('Failure Count', (string) $breaker->failure_count);
        $this->components->twoColumnDetail(
            'Last Failure',
            $breaker->last_failure_at?->format('Y-m-d H:i:s') ?? 'N/A',
        );
        $this->components->twoColumnDetail(
            'Last Success',
            $breaker->last_success_at?->format('Y-m-d H:i:s') ?? 'N/A',
        );
        $this->components->twoColumnDetail(
            'Opened At',
            $breaker->opened_at?->format('Y-m-d H:i:s') ?? 'N/A',
        );
        $this->components->twoColumnDetail(
            'Last Updated',
            $breaker->updated_at->format('Y-m-d H:i:s'),
        );

        $this->newLine();
        $this->components->info(sprintf('Description: %s', $breaker->state->description()));

        return self::SUCCESS;
    }

    /**
     * Manually open a circuit breaker.
     *
     * Forces the specified circuit into the Open state, preventing execution
     * of jobs for this service. Records the opened_at timestamp.
     *
     * @param string $serviceName The service name to open
     *
     * @return int Command exit code: self::SUCCESS (0) if circuit is opened,
     *             self::FAILURE (1) if validation fails
     */
    private function openCircuit(string $serviceName): int
    {
        if ($serviceName === '') {
            $this->components->error('Service name is required when using --open flag.');

            return self::FAILURE;
        }

        /** @var null|CircuitBreaker $breaker */
        $breaker = CircuitBreaker::query()
            ->where('service_name', $serviceName)
            ->first();

        if ($breaker === null) {
            $this->components->error(sprintf('Circuit breaker for service "%s" not found.', $serviceName));

            return self::FAILURE;
        }

        if ($breaker->state === CircuitBreakerState::Open) {
            $this->components->warn(sprintf('Circuit for "%s" is already open.', $serviceName));

            return self::SUCCESS;
        }

        $breaker->update([
            'state' => CircuitBreakerState::Open,
            'opened_at' => Date::now(),
        ]);

        $this->components->success(sprintf('Circuit for "%s" has been opened.', $serviceName));

        return self::SUCCESS;
    }

    /**
     * Manually close a circuit breaker.
     *
     * Forces the specified circuit into the Closed state, allowing normal
     * execution of jobs for this service. Does not reset failure count.
     *
     * @param string $serviceName The service name to close
     *
     * @return int Command exit code: self::SUCCESS (0) if circuit is closed,
     *             self::FAILURE (1) if validation fails
     */
    private function closeCircuit(string $serviceName): int
    {
        if ($serviceName === '') {
            $this->components->error('Service name is required when using --close flag.');

            return self::FAILURE;
        }

        /** @var null|CircuitBreaker $breaker */
        $breaker = CircuitBreaker::query()
            ->where('service_name', $serviceName)
            ->first();

        if ($breaker === null) {
            $this->components->error(sprintf('Circuit breaker for service "%s" not found.', $serviceName));

            return self::FAILURE;
        }

        if ($breaker->state === CircuitBreakerState::Closed) {
            $this->components->warn(sprintf('Circuit for "%s" is already closed.', $serviceName));

            return self::SUCCESS;
        }

        $breaker->update([
            'state' => CircuitBreakerState::Closed,
        ]);

        $this->components->success(sprintf('Circuit for "%s" has been closed.', $serviceName));
        $this->components->warn('Note: Failure count has not been reset. Use --reset to clear failures.');

        return self::SUCCESS;
    }

    /**
     * Reset a circuit breaker to initial state.
     *
     * Forces the specified circuit into the Closed state and resets all
     * failure metrics, effectively returning it to a fresh state.
     *
     * @param string $serviceName The service name to reset
     *
     * @return int Command exit code: self::SUCCESS (0) if circuit is reset,
     *             self::FAILURE (1) if validation fails
     */
    private function resetCircuit(string $serviceName): int
    {
        if ($serviceName === '') {
            $this->components->error('Service name is required when using --reset flag.');

            return self::FAILURE;
        }

        /** @var null|CircuitBreaker $breaker */
        $breaker = CircuitBreaker::query()
            ->where('service_name', $serviceName)
            ->first();

        if ($breaker === null) {
            $this->components->error(sprintf('Circuit breaker for service "%s" not found.', $serviceName));

            return self::FAILURE;
        }

        $breaker->update([
            'state' => CircuitBreakerState::Closed,
            'failure_count' => 0,
            'last_failure_at' => null,
            'opened_at' => null,
        ]);

        $this->components->success(sprintf('Circuit for "%s" has been reset to initial state.', $serviceName));

        return self::SUCCESS;
    }

    /**
     * Format circuit breaker state with color coding.
     *
     * @param CircuitBreakerState $state The circuit state to format
     *
     * @return string Formatted state with color tags
     */
    private function formatState(CircuitBreakerState $state): string
    {
        return sprintf('<fg=%s>%s</>', $state->color(), $state->label());
    }
}
