<?php

namespace HFlow\LaravelWorkflow\Models;

use HFlow\LaravelWorkflow\Enums\TransitionType;
use HFlow\LaravelWorkflow\Traits\HasAudit;
use HFlow\LaravelWorkflow\Traits\HasUuid;
use HFlow\LaravelWorkflow\Traits\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $workflow_id
 * @property int|null $from_step_id
 * @property int|null $to_step_id
 * @property int|null $action_id
 * @property int|null $condition_id
 * @property TransitionType $type
 * @property int $priority
 * @property-read WorkflowStepAction|null $action
 * @property-read WorkflowCondition|null $condition
 */
class WorkflowTransition extends Model
{
    use HasAudit;
    use HasFactory;
    use HasUuid;
    use SoftDeletes;

    /**
     * Apply tenant filtering through the parent workflow when enabled.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    protected $fillable = [
        'workflow_id',
        'from_step_id',
        'to_step_id',
        'action_id',
        'condition_id',
        'type',
        'priority',
    ];

    protected $casts = [
        'type' => TransitionType::class,
        'priority' => 'integer',
    ];

    /**
     * Get the configured workflow transitions table name.
     */
    public function getTable(): string
    {
        return config('workflow.table_prefix', 'workflow_').'workflow_transitions';
    }

    /**
     * Get the workflow definition that owns this transition.
     *
     * @return BelongsTo<Workflow, $this>
     */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    /**
     * Get the step this transition routes from, when configured.
     *
     * @return BelongsTo<WorkflowStep, $this>
     */
    public function fromStep(): BelongsTo
    {
        return $this->belongsTo(WorkflowStep::class, 'from_step_id');
    }

    /**
     * Get the step this transition routes to, when configured.
     *
     * @return BelongsTo<WorkflowStep, $this>
     */
    public function toStep(): BelongsTo
    {
        return $this->belongsTo(WorkflowStep::class, 'to_step_id');
    }

    /**
     * Get the action that triggers this transition, when configured.
     *
     * @return BelongsTo<WorkflowStepAction, $this>
     */
    public function action(): BelongsTo
    {
        return $this->belongsTo(WorkflowStepAction::class, 'action_id');
    }

    /**
     * Get the condition that must pass for this transition.
     *
     * @return BelongsTo<WorkflowCondition, $this>
     */
    public function condition(): BelongsTo
    {
        return $this->belongsTo(WorkflowCondition::class, 'condition_id');
    }
}
