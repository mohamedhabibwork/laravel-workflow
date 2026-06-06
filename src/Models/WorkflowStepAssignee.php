<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Models;

use HFlow\LaravelWorkflow\Enums\AssigneeType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A polymorphic authorization target for a workflow step.
 *
 * @property int $id
 * @property string $uuid
 * @property int $step_id
 * @property AssigneeType $assignee_type
 * @property string|null $assignee_value
 * @property string|null $custom_resolver
 * @property int $sort_order
 */
final class WorkflowStepAssignee extends WorkflowModel
{
    protected function tableName(): string
    {
        return 'workflow_step_assignees';
    }

    protected $fillable = [
        'uuid', 'step_id', 'assignee_type', 'assignee_value',
        'custom_resolver', 'sort_order',
        'is_deleted', 'deleted_at', 'created_by', 'updated_by', 'deleted_by',
    ];

    /**
     * @return array<string, string>
     */
    public function casts(): array
    {
        return array_merge(parent::casts(), [
            'assignee_type' => AssigneeType::class,
            'sort_order' => 'integer',
        ]);
    }

    /**
     * @return BelongsTo<WorkflowStep, WorkflowStepAssignee>
     */
    public function step(): BelongsTo
    {
        return $this->belongsTo(WorkflowStep::class, 'step_id');
    }
}
