<?php

namespace HFlow\LaravelWorkflow\Models;

use HFlow\LaravelWorkflow\Enums\StepStatus;
use HFlow\LaravelWorkflow\Traits\HasAudit;
use HFlow\LaravelWorkflow\Traits\HasUuid;
use HFlow\LaravelWorkflow\Traits\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $workflow_instance_id
 * @property int $step_id
 * @property StepStatus $status
 * @property int|null $acted_by
 * @property string|null $action_taken
 * @property string|null $comment
 * @property array<string, mixed>|null $data
 * @property-read WorkflowInstance $workflowInstance
 * @property-read WorkflowStep $step
 */
class WorkflowStepInstance extends Model
{
    use HasAudit;
    use HasFactory;
    use HasUuid;
    use SoftDeletes;

    /**
     * Apply tenant filtering through the parent workflow instance when enabled.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    protected $fillable = [
        'workflow_instance_id',
        'step_id',
        'status',
        'entered_at',
        'completed_at',
        'due_at',
        'acted_by',
        'action_taken',
        'comment',
        'data',
    ];

    protected $casts = [
        'status' => StepStatus::class,
        'entered_at' => 'datetime',
        'completed_at' => 'datetime',
        'due_at' => 'datetime',
        'data' => 'array',
    ];

    /**
     * Get the configured workflow step instances table name.
     */
    public function getTable(): string
    {
        return config('workflow.table_prefix', 'workflow_').'workflow_step_instances';
    }

    /**
     * Get the workflow instance that owns this runtime step.
     *
     * @return BelongsTo<WorkflowInstance, $this>
     */
    public function workflowInstance(): BelongsTo
    {
        return $this->belongsTo(WorkflowInstance::class);
    }

    /**
     * Get the definition step represented by this runtime step.
     *
     * @return BelongsTo<WorkflowStep, $this>
     */
    public function step(): BelongsTo
    {
        return $this->belongsTo(WorkflowStep::class);
    }

    /**
     * Get assignments created for this runtime step.
     *
     * @return HasMany<WorkflowAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(WorkflowAssignment::class, 'step_instance_id');
    }
}
