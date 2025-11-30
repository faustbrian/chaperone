<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Chaperone\Enums\MorphType;
use Cline\Chaperone\Enums\PrimaryKeyType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migration for creating Chaperone job supervision tables.
 *
 * This migration creates five tables for the Chaperone job supervision system:
 * - supervised_jobs: tracks all supervised jobs and their lifecycle
 * - heartbeats: records periodic health signals from running jobs
 * - circuit_breakers: manages circuit breaker state for protected services
 * - resource_violations: logs when jobs exceed resource limits
 * - job_health_checks: stores health check results for supervised jobs
 *
 * The primary key type (ID, ULID, UUID) is configured via the chaperone.primary_key_type
 * configuration option to support different application requirements.
 *
 * @see config/chaperone.php
 */
return new class() extends Migration
{
    /**
     * Run the migrations to create job supervision tables.
     */
    public function up(): void
    {
        $primaryKeyType = PrimaryKeyType::tryFrom(config('chaperone.primary_key_type', 'id')) ?? PrimaryKeyType::ID;
        $morphType = MorphType::tryFrom(config('chaperone.morph_type', 'string')) ?? MorphType::String;

        $connection = config('database.default');
        $useJsonb = DB::connection($connection)->getDriverName() === 'pgsql';

        // Create supervised_jobs table
        Schema::create(config('chaperone.table_names.supervised_jobs', 'supervised_jobs'), function (Blueprint $table) use ($primaryKeyType, $morphType, $useJsonb): void {
            match ($primaryKeyType) {
                PrimaryKeyType::ULID => $table->ulid('id')->primary(),
                PrimaryKeyType::UUID => $table->uuid('id')->primary(),
                PrimaryKeyType::ID => $table->id(),
            };

            $table->string('job_id')->unique()->comment('Queue job identifier');
            $table->string('job_class')->comment('Fully qualified job class name');

            // Who/what initiated this job
            match ($morphType) {
                MorphType::ULID => $table->nullableUlidMorphs('initiator'),
                MorphType::UUID => $table->nullableUuidMorphs('initiator'),
                MorphType::Numeric => $table->nullableNumericMorphs('initiator'),
                MorphType::String => $table->nullableMorphs('initiator'),
            };

            $table->timestamp('started_at')->comment('When job started execution');
            $table->timestamp('last_heartbeat_at')->nullable()->comment('Last heartbeat received');
            $table->timestamp('completed_at')->nullable()->comment('When job completed successfully');
            $table->timestamp('failed_at')->nullable()->comment('When job failed');
            $table->string('status')->default('running')->comment('Current job status');

            $useJsonb
                ? $table->jsonb('metadata')->nullable()->comment('Additional job metadata')
                : $table->json('metadata')->nullable()->comment('Additional job metadata');

            $table->timestamps();

            // Indexes for common query patterns
            $table->index('job_class', 'supervised_jobs_class_idx');
            $table->index('status', 'supervised_jobs_status_idx');
            $table->index('started_at', 'supervised_jobs_started_idx');
            $table->index(['initiator_type', 'initiator_id'], 'supervised_jobs_initiator_idx');
        });

        // Create supervised_job_errors table
        Schema::create(config('chaperone.table_names.supervised_job_errors', 'supervised_job_errors'), function (Blueprint $table) use ($primaryKeyType, $useJsonb): void {
            match ($primaryKeyType) {
                PrimaryKeyType::ULID => $table->ulid('id')->primary(),
                PrimaryKeyType::UUID => $table->uuid('id')->primary(),
                PrimaryKeyType::ID => $table->id(),
            };

            // Foreign key to supervised_jobs
            $supervisedJobsTable = config('chaperone.table_names.supervised_jobs', 'supervised_jobs');
            match ($primaryKeyType) {
                PrimaryKeyType::ULID => $table->foreignUlid('supervised_job_id')->constrained($supervisedJobsTable)->cascadeOnDelete(),
                PrimaryKeyType::UUID => $table->foreignUuid('supervised_job_id')->constrained($supervisedJobsTable)->cascadeOnDelete(),
                PrimaryKeyType::ID => $table->foreignId('supervised_job_id')->constrained($supervisedJobsTable)->cascadeOnDelete(),
            };

            $table->string('exception')->comment('Exception class name');
            $table->text('message')->comment('Exception message');
            $table->longText('trace')->comment('Stack trace');

            $useJsonb
                ? $table->jsonb('context')->nullable()->comment('Additional context data')
                : $table->json('context')->nullable()->comment('Additional context data');

            $table->timestamp('created_at')->comment('When error was recorded');

            $table->index('supervised_job_id', 'supervised_job_errors_job_idx');
            $table->index('created_at', 'supervised_job_errors_created_idx');
        });

        // Create heartbeats table
        Schema::create(config('chaperone.table_names.heartbeats', 'heartbeats'), function (Blueprint $table) use ($primaryKeyType, $useJsonb): void {
            match ($primaryKeyType) {
                PrimaryKeyType::ULID => $table->ulid('id')->primary(),
                PrimaryKeyType::UUID => $table->uuid('id')->primary(),
                PrimaryKeyType::ID => $table->id(),
            };

            // Foreign key to supervised_jobs
            $supervisedJobsTable = config('chaperone.table_names.supervised_jobs', 'supervised_jobs');
            match ($primaryKeyType) {
                PrimaryKeyType::ULID => $table->foreignUlid('supervised_job_id')->constrained($supervisedJobsTable)->cascadeOnDelete(),
                PrimaryKeyType::UUID => $table->foreignUuid('supervised_job_id')->constrained($supervisedJobsTable)->cascadeOnDelete(),
                PrimaryKeyType::ID => $table->foreignId('supervised_job_id')->constrained($supervisedJobsTable)->cascadeOnDelete(),
            };

            $table->timestamp('recorded_at')->comment('When heartbeat was recorded');
            $table->unsignedBigInteger('memory_usage')->nullable()->comment('Memory usage in bytes');
            $table->decimal('cpu_usage', 5, 2)->nullable()->comment('CPU usage percentage');
            $table->unsignedBigInteger('progress_current')->nullable()->comment('Current progress value');
            $table->unsignedBigInteger('progress_total')->nullable()->comment('Total progress value');

            $useJsonb
                ? $table->jsonb('metadata')->nullable()->comment('Additional heartbeat data')
                : $table->json('metadata')->nullable()->comment('Additional heartbeat data');

            $table->index('supervised_job_id', 'heartbeats_job_idx');
            $table->index('recorded_at', 'heartbeats_recorded_idx');
        });

        // Create circuit_breakers table
        Schema::create(config('chaperone.table_names.circuit_breakers', 'circuit_breakers'), function (Blueprint $table) use ($primaryKeyType): void {
            match ($primaryKeyType) {
                PrimaryKeyType::ULID => $table->ulid('id')->primary(),
                PrimaryKeyType::UUID => $table->uuid('id')->primary(),
                PrimaryKeyType::ID => $table->id(),
            };

            $table->string('service_name')->unique()->comment('Protected service identifier');
            $table->string('state')->default('closed')->comment('Circuit breaker state');
            $table->unsignedInteger('failure_count')->default(0)->comment('Consecutive failure count');
            $table->unsignedInteger('success_count')->default(0)->comment('Consecutive success count');
            $table->timestamp('last_failure_at')->nullable()->comment('When last failure occurred');
            $table->timestamp('opened_at')->nullable()->comment('When circuit was opened');
            $table->timestamp('last_success_at')->nullable()->comment('When last success occurred');
            $table->timestamps();

            $table->index('service_name', 'circuit_breakers_service_idx');
            $table->index('state', 'circuit_breakers_state_idx');
        });

        // Create resource_violations table
        Schema::create(config('chaperone.table_names.resource_violations', 'resource_violations'), function (Blueprint $table) use ($primaryKeyType, $useJsonb): void {
            match ($primaryKeyType) {
                PrimaryKeyType::ULID => $table->ulid('id')->primary(),
                PrimaryKeyType::UUID => $table->uuid('id')->primary(),
                PrimaryKeyType::ID => $table->id(),
            };

            // Foreign key to supervised_jobs
            $supervisedJobsTable = config('chaperone.table_names.supervised_jobs', 'supervised_jobs');
            match ($primaryKeyType) {
                PrimaryKeyType::ULID => $table->foreignUlid('supervised_job_id')->constrained($supervisedJobsTable)->cascadeOnDelete(),
                PrimaryKeyType::UUID => $table->foreignUuid('supervised_job_id')->constrained($supervisedJobsTable)->cascadeOnDelete(),
                PrimaryKeyType::ID => $table->foreignId('supervised_job_id')->constrained($supervisedJobsTable)->cascadeOnDelete(),
            };

            $table->string('violation_type')->comment('Type of resource violation');
            $table->unsignedBigInteger('limit_value')->comment('Configured limit value');
            $table->unsignedBigInteger('actual_value')->comment('Actual value that exceeded limit');
            $table->timestamp('recorded_at')->comment('When violation was recorded');

            $useJsonb
                ? $table->jsonb('metadata')->nullable()->comment('Additional violation context')
                : $table->json('metadata')->nullable()->comment('Additional violation context');

            $table->index('supervised_job_id', 'resource_violations_job_idx');
            $table->index('violation_type', 'resource_violations_type_idx');
            $table->index('recorded_at', 'resource_violations_recorded_idx');
        });

        // Create job_health_checks table
        Schema::create(config('chaperone.table_names.job_health_checks', 'job_health_checks'), function (Blueprint $table) use ($primaryKeyType, $useJsonb): void {
            match ($primaryKeyType) {
                PrimaryKeyType::ULID => $table->ulid('id')->primary(),
                PrimaryKeyType::UUID => $table->uuid('id')->primary(),
                PrimaryKeyType::ID => $table->id(),
            };

            // Foreign key to supervised_jobs
            $supervisedJobsTable = config('chaperone.table_names.supervised_jobs', 'supervised_jobs');
            match ($primaryKeyType) {
                PrimaryKeyType::ULID => $table->foreignUlid('supervised_job_id')->constrained($supervisedJobsTable)->cascadeOnDelete(),
                PrimaryKeyType::UUID => $table->foreignUuid('supervised_job_id')->constrained($supervisedJobsTable)->cascadeOnDelete(),
                PrimaryKeyType::ID => $table->foreignId('supervised_job_id')->constrained($supervisedJobsTable)->cascadeOnDelete(),
            };

            $table->boolean('is_healthy')->comment('Whether health check passed');
            $table->text('reason')->nullable()->comment('Reason for health check result');
            $table->timestamp('checked_at')->comment('When health check was performed');

            $useJsonb
                ? $table->jsonb('metadata')->nullable()->comment('Additional health check data')
                : $table->json('metadata')->nullable()->comment('Additional health check data');

            $table->index('supervised_job_id', 'job_health_checks_job_idx');
            $table->index('is_healthy', 'job_health_checks_healthy_idx');
            $table->index('checked_at', 'job_health_checks_checked_idx');
        });

        // Create dead_letter_queue table
        Schema::create(config('chaperone.table_names.dead_letter_queue', 'dead_letter_queue'), function (Blueprint $table) use ($primaryKeyType, $useJsonb): void {
            match ($primaryKeyType) {
                PrimaryKeyType::ULID => $table->ulid('id')->primary(),
                PrimaryKeyType::UUID => $table->uuid('id')->primary(),
                PrimaryKeyType::ID => $table->id(),
            };

            // Foreign key to supervised_jobs (nullable, set null on delete)
            $supervisedJobsTable = config('chaperone.table_names.supervised_jobs', 'supervised_jobs');
            match ($primaryKeyType) {
                PrimaryKeyType::ULID => $table->foreignUlid('supervised_job_id')->nullable()->constrained($supervisedJobsTable)->nullOnDelete(),
                PrimaryKeyType::UUID => $table->foreignUuid('supervised_job_id')->nullable()->constrained($supervisedJobsTable)->nullOnDelete(),
                PrimaryKeyType::ID => $table->foreignId('supervised_job_id')->nullable()->constrained($supervisedJobsTable)->nullOnDelete(),
            };

            $table->string('job_class')->comment('Fully qualified job class name');
            $table->string('exception')->comment('Exception class name');
            $table->text('message')->comment('Exception message');
            $table->longText('trace')->comment('Stack trace');

            $useJsonb
                ? $table->jsonb('payload')->nullable()->comment('Job payload for retry')
                : $table->json('payload')->nullable()->comment('Job payload for retry');

            $table->timestamp('failed_at')->comment('When job was moved to DLQ');
            $table->timestamp('retried_at')->nullable()->comment('When job was retried from DLQ');

            $table->index('supervised_job_id', 'dead_letter_queue_job_idx');
            $table->index('failed_at', 'dead_letter_queue_failed_idx');
            $table->index('job_class', 'dead_letter_queue_class_idx');
        });
    }

    /**
     * Reverse the migrations by dropping all job supervision tables.
     */
    public function down(): void
    {
        Schema::dropIfExists(config('chaperone.table_names.dead_letter_queue', 'dead_letter_queue'));
        Schema::dropIfExists(config('chaperone.table_names.job_health_checks', 'job_health_checks'));
        Schema::dropIfExists(config('chaperone.table_names.resource_violations', 'resource_violations'));
        Schema::dropIfExists(config('chaperone.table_names.circuit_breakers', 'circuit_breakers'));
        Schema::dropIfExists(config('chaperone.table_names.heartbeats', 'heartbeats'));
        Schema::dropIfExists(config('chaperone.table_names.supervised_job_errors', 'supervised_job_errors'));
        Schema::dropIfExists(config('chaperone.table_names.supervised_jobs', 'supervised_jobs'));
    }
};
