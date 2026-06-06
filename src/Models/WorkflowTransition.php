<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Models;

use HFlow\LaravelWorkflow\Enums\TransitionType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A directed edge between two steps (routing rule).
 *
 * @property int $id
 * @property string $uuid
 * @property int $workflow_id
 * @property int|null $from_step_id
 * @property int|null $to_step_id
 * @property int|null $action_id
 * @property int|null $condition_id
 * @property TransitionType $type
 * @property int $priority
 */
final class WorkflowTransition extends WorkflowModel
{
    protected function tableName(): string
    {
        return 'workflow_transitions';
    }

    protected $fillable = [
        'uuid', 'workflow_id', 'from_step_id', 'to_step_id',
        'action_id', 'condition_id', 'type', 'priority',
        'is_deleted', 'deleted_at', 'created_by', 'updated_by', 'deleted_by',
    ];

    /**
     * @return array<string, string>
     */
    public function casts(): array
    {
        return array_merge(parent::casts(), [
            'type' => TransitionType::class,
            'priority' => 'integer',
        ]);
    }

    /**
     * @return BelongsTo<Workflow, WorkflowTransition>
     */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class, 'workflow_id');
    }

    /**
     * @return BelongsTo<WorkflowStep, WorkflowTransition>
     */
    public function fromStep(): BelongsTo
    {
        return $this->belongsTo(WorkflowStep::class, 'from_step_id');
    }

    /**
     * @return BelongsTo<WorkflowStep, WorkflowTransition>
     */
    public function toStep(): BelongsTo
    {
        return $this->belongsTo(WorkflowStep::class, 'to_step_id');
    }

    /**
     * @return BelongsTo<WorkflowStepAction, WorkflowTransition>
     */
    public function action(): BelongsTo
    {
        return $this->belongsTo(WorkflowStepAction::class, 'action_id');
    }

    /**
     * @return BelongsTo<WorkflowCondition, WorkflowTransition>
     */
    public function condition(): BelongsTo
    {
        return $this->belongsTo(WorkflowCondition::class, 'condition_id');
    }
}
