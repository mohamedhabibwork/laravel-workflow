<?php

namespace HFlow\LaravelWorkflow\Models;

use HFlow\LaravelWorkflow\Enums\AuthorizationMode;
use HFlow\LaravelWorkflow\Enums\MatchMode;
use HFlow\LaravelWorkflow\Enums\StepType;
use HFlow\LaravelWorkflow\Traits\HasAudit;
use HFlow\LaravelWorkflow\Traits\HasUuid;
use HFlow\LaravelWorkflow\Traits\TenantScope;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $workflow_id
 * @property string $name
 * @property string $code
 * @property StepType $type
 * @property int $position
 * @property AuthorizationMode $authorization_mode
 * @property MatchMode $match_mode
 * @property string|null $custom_authorizer
 * @property string|null $handler
 * @property bool $is_skippable
 * @property bool $is_returnable
 * @property int|null $sla_seconds
 * @property array<string, mixed>|null $config
 * @property-read Collection<int, WorkflowStepAction> $actions
 * @property-read Collection<int, WorkflowStepAssignee> $assignees
 */
class WorkflowStep extends Model
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
        'name',
        'code',
        'description',
        'type',
        'position',
        'authorization_mode',
        'match_mode',
        'custom_authorizer',
        'handler',
        'is_skippable',
        'is_returnable',
        'sla_seconds',
        'config',
    ];

    protected $casts = [
        'type' => StepType::class,
        'authorization_mode' => AuthorizationMode::class,
        'match_mode' => MatchMode::class,
        'is_skippable' => 'boolean',
        'is_returnable' => 'boolean',
        'position' => 'integer',
        'sla_seconds' => 'integer',
        'config' => 'array',
    ];

    /**
     * Get the configured workflow steps table name.
     */
    public function getTable(): string
    {
        return config('workflow.table_prefix', 'workflow_').'workflow_steps';
    }

    /**
     * Get the workflow definition that owns the step.
     *
     * @return BelongsTo<Workflow, $this>
     */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    /**
     * Get assignee rules configured for this step.
     *
     * @return HasMany<WorkflowStepAssignee, $this>
     */
    public function assignees(): HasMany
    {
        return $this->hasMany(WorkflowStepAssignee::class, 'step_id');
    }

    /**
     * Get actions that can be performed while this step is active.
     *
     * @return HasMany<WorkflowStepAction, $this>
     */
    public function actions(): HasMany
    {
        return $this->hasMany(WorkflowStepAction::class, 'step_id');
    }

    /**
     * Get runtime step instances created from this definition step.
     *
     * @return HasMany<WorkflowStepInstance, $this>
     */
    public function stepInstances(): HasMany
    {
        return $this->hasMany(WorkflowStepInstance::class, 'step_id');
    }
}
