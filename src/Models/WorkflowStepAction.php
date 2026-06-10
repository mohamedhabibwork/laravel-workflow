<?php

namespace HFlow\LaravelWorkflow\Models;

use HFlow\LaravelWorkflow\Enums\ActionType;
use HFlow\LaravelWorkflow\Enums\AvailabilityMode;
use HFlow\LaravelWorkflow\Traits\HasAudit;
use HFlow\LaravelWorkflow\Traits\HasUuid;
use HFlow\LaravelWorkflow\Traits\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $step_id
 * @property string $name
 * @property string $code
 * @property string|null $label
 * @property ActionType $type
 * @property AvailabilityMode $availability_mode
 * @property int|null $guard_condition_id
 * @property string|null $guard_class
 * @property int|null $target_step_id
 * @property bool $requires_comment
 * @property string|null $handler
 * @property int $sort_order
 * @property-read WorkflowCondition|null $guardCondition
 * @property-read WorkflowStep $step
 */
class WorkflowStepAction extends Model
{
    use HasAudit;
    use HasFactory;
    use HasUuid;
    use SoftDeletes;

    /**
     * Apply tenant filtering through the parent step workflow when enabled.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    protected $fillable = [
        'step_id',
        'name',
        'code',
        'label',
        'type',
        'availability_mode',
        'guard_condition_id',
        'guard_class',
        'target_step_id',
        'requires_comment',
        'handler',
        'sort_order',
    ];

    protected $casts = [
        'type' => ActionType::class,
        'availability_mode' => AvailabilityMode::class,
        'requires_comment' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * Get the configured workflow step actions table name.
     */
    public function getTable(): string
    {
        return config('workflow.table_prefix', 'workflow_').'workflow_step_actions';
    }

    /**
     * Get the step that exposes this action.
     *
     * @return BelongsTo<WorkflowStep, $this>
     */
    public function step(): BelongsTo
    {
        return $this->belongsTo(WorkflowStep::class, 'step_id');
    }

    /**
     * Get the condition that gates action availability.
     *
     * @return BelongsTo<WorkflowCondition, $this>
     */
    public function guardCondition(): BelongsTo
    {
        return $this->belongsTo(WorkflowCondition::class, 'guard_condition_id');
    }

    /**
     * Get the explicit target step for this action, when configured.
     *
     * @return BelongsTo<WorkflowStep, $this>
     */
    public function targetStep(): BelongsTo
    {
        return $this->belongsTo(WorkflowStep::class, 'target_step_id');
    }
}
