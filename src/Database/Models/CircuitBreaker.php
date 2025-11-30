<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Chaperone\Database\Models;

use Cline\Chaperone\Database\Concerns\HasChaperonePrimaryKey;
use Cline\Chaperone\Enums\CircuitBreakerState;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Override;

/**
 * Eloquent model representing circuit breakers.
 *
 * Stores circuit breaker state for protected services, implementing the
 * circuit breaker pattern to prevent cascading failures. Tracks failure
 * counts, state transitions, and recovery attempts.
 *
 * Circuit breakers protect against cascading failures by temporarily
 * blocking requests to failing services, allowing them time to recover.
 * The state transitions between Closed (normal), Open (blocking), and
 * HalfOpen (testing recovery).
 *
 * @property Carbon                $created_at        Record creation timestamp
 * @property int                   $failure_count     Consecutive failure count
 * @property mixed                 $id                Primary key (auto-increment, UUID, or ULID)
 * @property null|Carbon           $last_failure_at   When last failure occurred
 * @property null|Carbon           $last_success_at   When last success occurred
 * @property null|Carbon           $opened_at         When circuit was opened
 * @property string                $service_name      Protected service identifier
 * @property CircuitBreakerState   $state             Circuit breaker state
 * @property Carbon                $updated_at        Record update timestamp
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class CircuitBreaker extends Model
{
    use HasChaperonePrimaryKey;
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * Allows bulk assignment of circuit breaker fields during creation and updates.
     * All state-tracking fields are fillable to support various circuit breaker
     * management scenarios.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'service_name',
        'state',
        'failure_count',
        'success_count',
        'last_failure_at',
        'opened_at',
        'last_success_at',
    ];

    /**
     * Get the table name from configuration.
     *
     * Retrieves the configured table name from chaperone.table_names.circuit_breakers,
     * defaulting to 'circuit_breakers' if not configured. Allows customization of the
     * table name without modifying the model.
     *
     * @return string The configured table name for circuit breaker storage
     */
    #[Override()]
    public function getTable(): string
    {
        /** @var string */
        return Config::get('chaperone.table_names.circuit_breakers', 'circuit_breakers');
    }

    /**
     * Get the attribute casting configuration.
     *
     * Configures automatic casting of timestamp columns to Carbon instances for
     * convenient date/time manipulation and formatting throughout the application.
     * Also casts the state field to the CircuitBreakerState enum for type-safe
     * state checking and transitions.
     *
     * @return array<string, string> Attribute casting map
     */
    protected function casts(): array
    {
        return [
            'state' => CircuitBreakerState::class,
            'failure_count' => 'integer',
            'success_count' => 'integer',
            'last_failure_at' => 'datetime',
            'opened_at' => 'datetime',
            'last_success_at' => 'datetime',
        ];
    }
}
