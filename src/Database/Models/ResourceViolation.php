<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Chaperone\Database\Models;

use Cline\VariableKeys\Database\Concerns\HasVariablePrimaryKey;
use Cline\Chaperone\Enums\ResourceViolationType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Override;

/**
 * Eloquent model representing resource violations.
 *
 * Stores records of when supervised jobs exceed configured resource limits,
 * tracking the violation type, limit values, and actual usage. Enables
 * monitoring and enforcement of resource constraints.
 *
 * Each resource violation captures the specific limit that was exceeded,
 * the configured threshold, and the actual value that triggered the violation.
 * This enables trend analysis and identification of resource-intensive jobs.
 *
 * @property int                       $actual_value      Actual value that exceeded limit
 * @property mixed                     $id                Primary key (auto-increment, UUID, or ULID)
 * @property int                       $limit_value       Configured limit value
 * @property null|array<string, mixed> $metadata          Additional violation context
 * @property Carbon                    $recorded_at       When violation was recorded
 * @property mixed                     $supervised_job_id Foreign key to supervised job
 * @property SupervisedJob             $supervisedJob     Related supervised job
 * @property ResourceViolationType     $violation_type    Type of resource violation
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class ResourceViolation extends Model
{
    use HasVariablePrimaryKey;
    use HasFactory;

    /**
     * Indicates if the model should be timestamped.
     *
     * Disabled because the model uses a custom timestamp field (recorded_at)
     * instead of Laravel's standard created_at and updated_at fields.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * Allows bulk assignment of resource violation fields during creation.
     * All violation-tracking fields are fillable to support various
     * monitoring scenarios.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'supervised_job_id',
        'violation_type',
        'limit_value',
        'actual_value',
        'recorded_at',
        'metadata',
    ];

    /**
     * Get the table name from configuration.
     *
     * Retrieves the configured table name from chaperone.table_names.resource_violations,
     * defaulting to 'resource_violations' if not configured. Allows customization of the
     * table name without modifying the model.
     *
     * @return string The configured table name for resource violation storage
     */
    #[Override()]
    public function getTable(): string
    {
        /** @var string */
        return Config::get('chaperone.table_names.resource_violations', 'resource_violations');
    }

    /**
     * Get the supervised job this violation belongs to.
     *
     * Defines a many-to-one relationship to the supervised job that triggered
     * this violation. Each violation is associated with exactly one supervised job.
     *
     * @return BelongsTo<SupervisedJob, $this> Relation to the supervised job
     */
    public function supervisedJob(): BelongsTo
    {
        /** @var class-string<SupervisedJob> $model */
        $model = Config::get('chaperone.models.supervised_job', SupervisedJob::class);

        return $this->belongsTo($model, 'supervised_job_id');
    }

    /**
     * Get the attribute casting configuration.
     *
     * Configures automatic casting of the recorded_at column to Carbon instance
     * for convenient date/time manipulation. Violation type is cast to enum for
     * type-safe checking. Metadata is cast to array for easy access.
     *
     * @return array<string, string> Attribute casting map
     */
    protected function casts(): array
    {
        return [
            'violation_type' => ResourceViolationType::class,
            'limit_value' => 'integer',
            'actual_value' => 'integer',
            'recorded_at' => 'datetime',
            'metadata' => 'array',
        ];
    }
}
