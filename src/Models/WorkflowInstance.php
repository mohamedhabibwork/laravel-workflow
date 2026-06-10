<?php

namespace HFlow\LaravelWorkflow\Models;

use HFlow\LaravelWorkflow\Enums\InstanceStatus;
use HFlow\LaravelWorkflow\Traits\HasAudit;
use HFlow\LaravelWorkflow\Traits\HasUuid;
use HFlow\LaravelWorkflow\Traits\TenantScope;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $tenant_id
 * @property int $workflow_id
 * @property int $workflow_version
 * @property string|null $workflow_identity
 * @property string|null $run_id
 * @property string|null $first_execution_run_id
 * @property int|null $parent_instance_id
 * @property string $subject_type
 * @property int $subject_id
 * @property int|null $current_step_id
 * @property InstanceStatus $status
 * @property array<string, mixed>|null $context
 * @property array<string, mixed>|null $memo
 * @property array<string, mixed>|null $search_attributes
 * @property string|null $task_queue
 * @property Carbon|null $start_after
 * @property Carbon|null $execution_timeout_at
 * @property Carbon|null $run_timeout_at
 * @property-read Workflow $workflow
 * @property-read Model $subject
 * @property-read Collection<int, WorkflowTimer> $timers
 * @property-read Collection<int, WorkflowActivity> $activities
 */
class WorkflowInstance extends Model
{
    use HasAudit;
    use HasFactory;
    use HasUuid;
    use SoftDeletes;

    /**
     * Apply tenant filtering when package multi-tenancy is enabled.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    protected $fillable = [
        'tenant_id',
        'workflow_id',
        'workflow_version',
        'workflow_identity',
        'run_id',
        'first_execution_run_id',
        'parent_instance_id',
        'subject_type',
        'subject_id',
        'current_step_id',
        'status',
        'context',
        'memo',
        'search_attributes',
        'task_queue',
        'initiated_by',
        'started_at',
        'start_after',
        'execution_timeout_at',
        'run_timeout_at',
        'completed_at',
    ];

    protected $casts = [
        'status' => InstanceStatus::class,
        'context' => 'array',
        'memo' => 'array',
        'search_attributes' => 'array',
        'started_at' => 'datetime',
        'start_after' => 'datetime',
        'execution_timeout_at' => 'datetime',
        'run_timeout_at' => 'datetime',
        'completed_at' => 'datetime',
        'workflow_version' => 'integer',
    ];

    /**
     * Get the configured workflow instances table name.
     */
    public function getTable(): string
    {
        return config('workflow.table_prefix', 'workflow_').'workflow_instances';
    }

    /**
     * Get the workflow definition version used by this instance.
     *
     * @return BelongsTo<Workflow, $this>
     */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    /**
     * Get the model subject that this workflow instance runs against.
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the current definition step for this instance.
     *
     * @return BelongsTo<WorkflowStep, $this>
     */
    public function currentStep(): BelongsTo
    {
        return $this->belongsTo(WorkflowStep::class, 'current_step_id');
    }

    /**
     * Get the parent workflow instance, when this run was started as a child.
     *
     * @return BelongsTo<WorkflowInstance, $this>
     */
    public function parentInstance(): BelongsTo
    {
        return $this->belongsTo(WorkflowInstance::class, 'parent_instance_id');
    }

    /**
     * Get all runtime step instances for this workflow instance.
     *
     * @return HasMany<WorkflowStepInstance, $this>
     */
    public function stepInstances(): HasMany
    {
        return $this->hasMany(WorkflowStepInstance::class);
    }

    /**
     * Get the immutable history entries for this workflow instance.
     *
     * @return HasMany<WorkflowHistory, $this>
     */
    public function histories(): HasMany
    {
        return $this->hasMany(WorkflowHistory::class);
    }

    /**
     * Get timers scheduled for this workflow instance.
     *
     * @return HasMany<WorkflowTimer, $this>
     */
    public function timers(): HasMany
    {
        return $this->hasMany(WorkflowTimer::class);
    }

    /**
     * Get activities scheduled for this workflow instance.
     *
     * @return HasMany<WorkflowActivity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(WorkflowActivity::class);
    }
}
