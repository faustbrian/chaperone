<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Chaperone\Database\Models;

use Cline\Chaperone\Database\Concerns\HasChaperonePrimaryKey;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Override;

/**
 * Eloquent model representing supervised job execution errors.
 *
 * Stores detailed error information when supervised jobs fail, including exception
 * messages, stack traces, and contextual data for debugging and recovery.
 * Provides a complete audit trail of all job failures.
 *
 * Each error record captures the full exception details at the moment of failure,
 * preserving critical debugging information even if the job is retried or
 * rolled back. The context field stores additional data like request parameters,
 * user state, or environment variables that may be relevant for troubleshooting.
 *
 * @property null|array<string, mixed> $context           Additional context data (request params, state, etc.)
 * @property Carbon                    $created_at        When error was recorded
 * @property string                    $exception         Exception class name
 * @property mixed                     $id                Primary key (auto-increment, UUID, or ULID)
 * @property string                    $message           Exception message
 * @property SupervisedJob             $supervisedJob     The supervised job that failed
 * @property mixed                     $supervised_job_id Foreign key to supervised_jobs table
 * @property string                    $trace             Stack trace
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class SupervisedJobError extends Model
{
    /** @use HasFactory<Factory<SupervisedJobError>> */
    use HasFactory;
    use HasChaperonePrimaryKey;

    /**
     * Indicates if the model should be timestamped.
     *
     * Disabled because the model uses only created_at without updated_at,
     * since error records are immutable after creation. Errors capture a
     * point-in-time snapshot of failure state and should never be modified.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * Allows bulk assignment of all error fields during creation. All fields are
     * fillable since error records are created programmatically from caught exceptions
     * rather than user input, eliminating mass assignment vulnerability concerns.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'supervised_job_id',
        'exception',
        'message',
        'trace',
        'context',
        'created_at',
    ];

    /**
     * Get the table name from configuration.
     *
     * Retrieves the configured table name from chaperone.table_names.supervised_job_errors,
     * defaulting to 'supervised_job_errors' if not configured. Allows customization of the
     * table name without modifying the model.
     *
     * @return string The configured table name for supervised job error storage
     */
    #[Override()]
    public function getTable(): string
    {
        /** @var string */
        return Config::get('chaperone.table_names.supervised_job_errors', 'supervised_job_errors');
    }

    /**
     * Get the supervised job this error belongs to.
     *
     * Defines a many-to-one relationship back to the parent SupervisedJob model that
     * experienced this error. Enables querying all errors for a specific job
     * or navigating from error to job for context.
     *
     * @return BelongsTo<SupervisedJob, $this> Parent supervised job relationship
     */
    public function supervisedJob(): BelongsTo
    {
        /** @var class-string<SupervisedJob> $supervisedJobModel */
        $supervisedJobModel = Config::get('chaperone.models.supervised_job', SupervisedJob::class);

        return $this->belongsTo($supervisedJobModel, 'supervised_job_id');
    }

    /**
     * Get the attribute casting configuration.
     *
     * Configures automatic casting for the context array and created_at timestamp,
     * enabling convenient access to structured error context and datetime manipulation.
     * The context field is automatically serialized/unserialized as JSON when stored
     * in the database.
     *
     * @return array<string, string> Attribute casting map
     */
    protected function casts(): array
    {
        return [
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
