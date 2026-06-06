<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

it('loads the combined workflow migration successfully', function (): void {
    $this->loadWorkflowMigrations();

    $prefix = (string) config('workflow.table_prefix', 'workflow_');
    $tables = [
        'workflows',
        'workflow_steps',
        'workflow_step_assignees',
        'workflow_step_actions',
        'workflow_conditions',
        'workflow_transitions',
        'workflow_instances',
        'workflow_step_instances',
        'workflow_assignments',
        'workflow_histories',
    ];

    foreach ($tables as $table) {
        expect(Schema::hasTable($prefix.$table))->toBeTrue("Table [{$prefix}{$table}] should exist");
    }
});

it('has the expected columns on workflow_instances', function (): void {
    $this->loadWorkflowMigrations();

    $prefix = (string) config('workflow.table_prefix', 'workflow_');
    $table = $prefix.'workflow_instances';

    expect(Schema::hasColumn($table, 'id'))->toBeTrue();
    expect(Schema::hasColumn($table, 'uuid'))->toBeTrue();
    expect(Schema::hasColumn($table, 'tenant_id'))->toBeTrue();
    expect(Schema::hasColumn($table, 'workflow_id'))->toBeTrue();
    expect(Schema::hasColumn($table, 'workflow_version'))->toBeTrue();
    expect(Schema::hasColumn($table, 'subject_type'))->toBeTrue();
    expect(Schema::hasColumn($table, 'subject_id'))->toBeTrue();
    expect(Schema::hasColumn($table, 'current_step_id'))->toBeTrue();
    expect(Schema::hasColumn($table, 'status'))->toBeTrue();
    expect(Schema::hasColumn($table, 'context'))->toBeTrue();
    expect(Schema::hasColumn($table, 'initiated_by'))->toBeTrue();
    expect(Schema::hasColumn($table, 'started_at'))->toBeTrue();
    expect(Schema::hasColumn($table, 'completed_at'))->toBeTrue();
    expect(Schema::hasColumn($table, 'is_deleted'))->toBeTrue();
    expect(Schema::hasColumn($table, 'deleted_at'))->toBeTrue();
    expect(Schema::hasColumn($table, 'created_at'))->toBeTrue();
    expect(Schema::hasColumn($table, 'updated_at'))->toBeTrue();
});

it('workflow_histories is append-only (no updated_at, no soft delete)', function (): void {
    $this->loadWorkflowMigrations();

    $prefix = (string) config('workflow.table_prefix', 'workflow_');
    $table = $prefix.'workflow_histories';

    expect(Schema::hasColumn($table, 'id'))->toBeTrue();
    expect(Schema::hasColumn($table, 'uuid'))->toBeTrue();
    expect(Schema::hasColumn($table, 'workflow_instance_id'))->toBeTrue();
    expect(Schema::hasColumn($table, 'event'))->toBeTrue();
    expect(Schema::hasColumn($table, 'actor_type'))->toBeTrue();
    expect(Schema::hasColumn($table, 'performed_at'))->toBeTrue();
    expect(Schema::hasColumn($table, 'created_at'))->toBeTrue();

    // Append-only: no updated_at, no is_deleted, no deleted_at
    expect(Schema::hasColumn($table, 'updated_at'))->toBeFalse();
    expect(Schema::hasColumn($table, 'is_deleted'))->toBeFalse();
    expect(Schema::hasColumn($table, 'deleted_at'))->toBeFalse();
});

it('honors a custom table prefix from config', function (): void {
    config()->set('workflow.table_prefix', 'custom_');
    $this->loadWorkflowMigrations();

    expect(Schema::hasTable('custom_workflow_instances'))->toBeTrue();
    expect(Schema::hasTable('workflow_instances'))->toBeFalse();
});
