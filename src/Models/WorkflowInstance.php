<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Models;

use HFlow\LaravelWorkflow\Concerns\AppliesTenantScope;
use HFlow\LaravelWorkflow\Enums\InstanceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * A running workflow instance bound to a host model.
 *
 * @property int $id
 * @property string $uuid
 * @property int|null $tenant_id
 * @property int $workflow_id
 * @property int $workflow_version
 * @property string $subject_type
 * @property int $subject_id
 * @property int|null $current_step_id
 * @property InstanceStatus $status
 * @property array<string, mixed>|null $context
 * @property int|null $initiated_by
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 */
final class WorkflowInstance extends WorkflowModel
{
    use AppliesTenantScope;

    protected function tableName(): string
    {
        return 'instances';
    }

    protected $fillable = [
        'uuid', 'tenant_id', 'workflow_id', 'workflow_version',
        'subject_type', 'subject_id', 'current_step_id', 'status',
        'context', 'initiated_by', 'started_at', 'completed_at',
        'is_deleted', 'deleted_at', 'created_by', 'updated_by', 'deleted_by',
    ];

    /**
     * @return array<string, string>
     */
    public function casts(): array
    {
        return array_merge(parent::casts(), [
            'status' => InstanceStatus::class,
            'context' => 'array',
            'workflow_version' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ]);
    }

    /**
     * @return BelongsTo<Workflow, WorkflowInstance>
     */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class, 'workflow_id');
    }

    /**
     * @return BelongsTo<WorkflowStep, WorkflowInstance>
     */
    public function currentStep(): BelongsTo
    {
        return $this->belongsTo(WorkflowStep::class, 'current_step_id');
    }

    /**
     * @return MorphTo<Model, WorkflowInstance>
     */
    public function workflowable(): MorphTo
    {
        return $this->morphTo('subject', 'subject_type', 'subject_id');
    }

    /**
     * @return HasMany<WorkflowStepInstance>
     */
    public function stepInstances(): HasMany
    {
        return $this->hasMany(WorkflowStepInstance::class, 'workflow_instance_id');
    }

    /**
     * @return HasMany<WorkflowHistory>
     */
    public function histories(): HasMany
    {
        return $this->hasMany(WorkflowHistory::class, 'workflow_instance_id');
    }
}
