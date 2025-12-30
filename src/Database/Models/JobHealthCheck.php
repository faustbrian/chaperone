<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Chaperone\Database\Models;

use Cline\VariableKeys\Database\Concerns\HasVariablePrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Override;

/**
 * Eloquent model representing job health checks.
 *
 * Stores health check results for supervised jobs, recording whether the
 * job is healthy and providing diagnostic information when issues are detected.
 * Enables proactive monitoring and early detection of job problems.
 *
 * Each health check captures a snapshot of the job's health at a specific
 * point in time, including whether the check passed and detailed reasoning
 * for any failures. This enables trend analysis and early intervention.
 *
 * @property Carbon                    $checked_at        When health check was performed
 * @property mixed                     $id                Primary key (auto-increment, UUID, or ULID)
 * @property bool                      $is_healthy        Whether health check passed
 * @property null|array<string, mixed> $metadata          Additional health check data
 * @property null|string               $reason            Reason for health check result
 * @property mixed                     $supervised_job_id Foreign key to supervised job
 * @property SupervisedJob             $supervisedJob     Related supervised job
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class JobHealthCheck extends Model
{
    use HasVariablePrimaryKey;
    use HasFactory;

    /**
     * Indicates if the model should be timestamped.
     *
     * Disabled because the model uses a custom timestamp field (checked_at)
     * instead of Laravel's standard created_at and updated_at fields.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * Allows bulk assignment of health check fields during creation.
     * All diagnostic fields are fillable to support various health
     * monitoring scenarios.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'supervised_job_id',
        'is_healthy',
        'reason',
        'checked_at',
        'metadata',
    ];

    /**
     * Get the table name from configuration.
     *
     * Retrieves the configured table name from chaperone.table_names.job_health_checks,
     * defaulting to 'job_health_checks' if not configured. Allows customization of the
     * table name without modifying the model.
     *
     * @return string The configured table name for health check storage
     */
    #[Override()]
    public function getTable(): string
    {
        /** @var string */
        return Config::get('chaperone.table_names.job_health_checks', 'job_health_checks');
    }

    /**
     * Get the supervised job this health check belongs to.
     *
     * Defines a many-to-one relationship to the supervised job that was checked.
     * Each health check is associated with exactly one supervised job.
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
     * Configures automatic casting of the checked_at column to Carbon instance
     * for convenient date/time manipulation. Health status is cast to boolean
     * and metadata to array for easy access to diagnostic information.
     *
     * @return array<string, string> Attribute casting map
     */
    protected function casts(): array
    {
        return [
            'is_healthy' => 'boolean',
            'checked_at' => 'datetime',
            'metadata' => 'array',
        ];
    }
}
