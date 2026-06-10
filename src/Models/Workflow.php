<?php

namespace HFlow\LaravelWorkflow\Models;

use HFlow\LaravelWorkflow\Enums\WorkflowStatus;
use HFlow\LaravelWorkflow\Enums\WorkflowType;
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
 * @property string $name
 * @property string $code
 * @property WorkflowType $type
 * @property int $version
 * @property bool $is_current_version
 * @property WorkflowStatus $status
 * @property int|null $start_step_id
 * @property bool $require_explicit_transitions
 * @property array<string, mixed>|null $config
 * @property-read WorkflowStep|null $startStep
 * @property-read Collection<int, WorkflowStep> $steps
 * @property-read Collection<int, WorkflowTransition> $transitions
 */
class Workflow extends Model
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
        'name',
        'code',
        'description',
        'type',
        'subject_type',
        'version',
        'is_current_version',
        'status',
        'start_step_id',
        'require_explicit_transitions',
        'config',
        'tenant_id',
    ];

    protected $casts = [
        'type' => WorkflowType::class,
        'status' => WorkflowStatus::class,
        'is_current_version' => 'boolean',
        'require_explicit_transitions' => 'boolean',
        'config' => 'array',
        'version' => 'integer',
    ];

    /**
     * Get the configured workflow definitions table name.
     */
    public function getTable(): string
    {
        return config('workflow.table_prefix', 'workflow_').'workflows';
    }

    /**
     * Get the ordered definition steps that belong to the workflow.
     *
     * @return HasMany<WorkflowStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(WorkflowStep::class);
    }

    /**
     * Get the configured start step for the workflow.
     *
     * @return BelongsTo<WorkflowStep, $this>
     */
    public function startStep(): BelongsTo
    {
        return $this->belongsTo(WorkflowStep::class, 'start_step_id');
    }

    /**
     * Get the transitions that define routing between workflow steps.
     *
     * @return HasMany<WorkflowTransition, $this>
     */
    public function transitions(): HasMany
    {
        return $this->hasMany(WorkflowTransition::class);
    }

    /**
     * Get reusable conditions attached to this workflow definition.
     *
     * @return HasMany<WorkflowCondition, $this>
     */
    public function conditions(): HasMany
    {
        return $this->hasMany(WorkflowCondition::class);
    }

    /**
     * Get runtime instances started from this workflow definition.
     *
     * @return HasMany<WorkflowInstance, $this>
     */
    public function instances(): HasMany
    {
        return $this->hasMany(WorkflowInstance::class);
    }
}
