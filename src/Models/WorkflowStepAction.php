<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Models;

use HFlow\LaravelWorkflow\Enums\ActionAvailabilityMode;
use HFlow\LaravelWorkflow\Enums\ActionType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An action offered on a workflow step.
 *
 * @property int $id
 * @property string $uuid
 * @property int $step_id
 * @property string $name
 * @property string $code
 * @property string|null $label
 * @property ActionType $type
 * @property ActionAvailabilityMode $availability_mode
 * @property int|null $guard_condition_id
 * @property string|null $guard_class
 * @property int|null $target_step_id
 * @property bool $requires_comment
 * @property string|null $handler
 * @property int $sort_order
 */
final class WorkflowStepAction extends WorkflowModel
{
    protected function tableName(): string
    {
        return 'step_actions';
    }

    protected $fillable = [
        'uuid', 'step_id', 'name', 'code', 'label', 'type', 'availability_mode',
        'guard_condition_id', 'guard_class', 'target_step_id',
        'requires_comment', 'handler', 'sort_order', 'config',
        'is_deleted', 'deleted_at', 'created_by', 'updated_by', 'deleted_by',
    ];

    /**
     * @return array<string, string>
     */
    public function casts(): array
    {
        return array_merge(parent::casts(), [
            'type' => ActionType::class,
            'availability_mode' => ActionAvailabilityMode::class,
            'requires_comment' => 'boolean',
            'sort_order' => 'integer',
            'config' => 'array',
        ]);
    }

    /**
     * @return BelongsTo<WorkflowStep, WorkflowStepAction>
     */
    public function step(): BelongsTo
    {
        return $this->belongsTo(WorkflowStep::class, 'step_id');
    }

    /**
     * @return BelongsTo<WorkflowCondition, WorkflowStepAction>
     */
    public function guardCondition(): BelongsTo
    {
        return $this->belongsTo(WorkflowCondition::class, 'guard_condition_id');
    }

    /**
     * @return BelongsTo<WorkflowStep, WorkflowStepAction>
     */
    public function targetStep(): BelongsTo
    {
        return $this->belongsTo(WorkflowStep::class, 'target_step_id');
    }
}
