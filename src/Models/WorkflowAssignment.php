<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Models;

use HFlow\LaravelWorkflow\Enums\AssignmentStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A runtime task assignment (who is on the hook for this step).
 *
 * @property int $id
 * @property string $uuid
 * @property int $step_instance_id
 * @property int $assignee_id
 * @property AssignmentStatus $status
 * @property \Illuminate\Support\Carbon|null $assigned_at
 * @property \Illuminate\Support\Carbon|null $acted_at
 */
final class WorkflowAssignment extends WorkflowModel
{
    protected function tableName(): string
    {
        return 'workflow_assignments';
    }

    protected $fillable = [
        'uuid', 'step_instance_id', 'assignee_id', 'status',
        'assigned_at', 'acted_at',
        'is_deleted', 'deleted_at', 'created_by', 'updated_by', 'deleted_by',
    ];

    /**
     * @return array<string, string>
     */
    public function casts(): array
    {
        return array_merge(parent::casts(), [
            'status' => AssignmentStatus::class,
            'assigned_at' => 'datetime',
            'acted_at' => 'datetime',
        ]);
    }

    /**
     * @return BelongsTo<WorkflowStepInstance, WorkflowAssignment>
     */
    public function stepInstance(): BelongsTo
    {
        return $this->belongsTo(WorkflowStepInstance::class, 'step_instance_id');
    }
}
