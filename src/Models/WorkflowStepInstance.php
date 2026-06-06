<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Models;

use HFlow\LaravelWorkflow\Enums\StepInstanceStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A per-step runtime record for a workflow instance.
 *
 * @property int $id
 * @property string $uuid
 * @property int $workflow_instance_id
 * @property int $step_id
 * @property StepInstanceStatus $status
 * @property \Illuminate\Support\Carbon|null $entered_at
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property \Illuminate\Support\Carbon|null $due_at
 * @property int|null $acted_by
 * @property string|null $action_taken
 * @property string|null $comment
 * @property array<string, mixed>|null $data
 */
final class WorkflowStepInstance extends WorkflowModel
{
    protected function tableName(): string
    {
        return 'workflow_step_instances';
    }

    protected $fillable = [
        'uuid', 'workflow_instance_id', 'step_id', 'status',
        'entered_at', 'completed_at', 'due_at', 'acted_by',
        'action_taken', 'comment', 'data',
        'is_deleted', 'deleted_at', 'created_by', 'updated_by', 'deleted_by',
    ];

    /**
     * @return array<string, string>
     */
    public function casts(): array
    {
        return array_merge(parent::casts(), [
            'status' => StepInstanceStatus::class,
            'entered_at' => 'datetime',
            'completed_at' => 'datetime',
            'due_at' => 'datetime',
            'data' => 'array',
        ]);
    }

    /**
     * @return BelongsTo<WorkflowInstance, WorkflowStepInstance>
     */
    public function instance(): BelongsTo
    {
        return $this->belongsTo(WorkflowInstance::class, 'workflow_instance_id');
    }

    /**
     * @return BelongsTo<WorkflowStep, WorkflowStepInstance>
     */
    public function step(): BelongsTo
    {
        return $this->belongsTo(WorkflowStep::class, 'step_id');
    }

    /**
     * @return HasMany<WorkflowAssignment>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(WorkflowAssignment::class, 'step_instance_id');
    }

    /**
     * @return HasMany<WorkflowHistory>
     */
    public function histories(): HasMany
    {
        return $this->hasMany(WorkflowHistory::class, 'step_instance_id');
    }
}
