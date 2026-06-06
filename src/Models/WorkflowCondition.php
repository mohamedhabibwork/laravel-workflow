<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Models;

use HFlow\LaravelWorkflow\Enums\ConditionKind;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A reusable boolean guard for a workflow.
 *
 * @property int $id
 * @property string $uuid
 * @property int|null $workflow_id
 * @property string $name
 * @property string $code
 * @property ConditionKind $kind
 * @property array<string, mixed>|null $expression
 * @property string|null $evaluator
 */
final class WorkflowCondition extends WorkflowModel
{
    protected function tableName(): string
    {
        return 'workflow_conditions';
    }

    protected $fillable = [
        'uuid', 'workflow_id', 'name', 'code', 'kind', 'expression', 'evaluator',
        'is_deleted', 'deleted_at', 'created_by', 'updated_by', 'deleted_by',
    ];

    /**
     * @return array<string, string>
     */
    public function casts(): array
    {
        return array_merge(parent::casts(), [
            'kind' => ConditionKind::class,
            'expression' => 'array',
        ]);
    }

    /**
     * @return BelongsTo<Workflow, WorkflowCondition>
     */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class, 'workflow_id');
    }

    /**
     * @return HasMany<WorkflowStepAction>
     */
    public function guardedActions(): HasMany
    {
        return $this->hasMany(WorkflowStepAction::class, 'guard_condition_id');
    }

    /**
     * @return HasMany<WorkflowTransition>
     */
    public function guardedTransitions(): HasMany
    {
        return $this->hasMany(WorkflowTransition::class, 'condition_id');
    }
}
