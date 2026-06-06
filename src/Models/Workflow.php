<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Models;

use HFlow\LaravelWorkflow\Enums\WorkflowStatus;
use HFlow\LaravelWorkflow\Enums\WorkflowType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A versioned workflow definition (design-time).
 *
 * @property int $id
 * @property string $uuid
 * @property int|null $tenant_id
 * @property string $name
 * @property string $code
 * @property string|null $description
 * @property WorkflowType $type
 * @property string|null $subject_type
 * @property int $version
 * @property bool $is_current_version
 * @property WorkflowStatus $status
 * @property int|null $start_step_id
 * @property bool $require_explicit_transitions
 * @property array<string, mixed>|null $config
 */
final class Workflow extends WorkflowModel
{
    protected function tableName(): string
    {
        return 'workflows';
    }

    protected $fillable = [
        'uuid', 'tenant_id', 'name', 'code', 'description',
        'type', 'subject_type', 'version', 'is_current_version',
        'status', 'start_step_id', 'require_explicit_transitions', 'config',
        'is_deleted', 'deleted_at', 'created_by', 'updated_by', 'deleted_by',
    ];

    /**
     * @return array<string, string>
     */
    public function casts(): array
    {
        return array_merge(parent::casts(), [
            'type' => WorkflowType::class,
            'status' => WorkflowStatus::class,
            'is_current_version' => 'boolean',
            'require_explicit_transitions' => 'boolean',
            'config' => 'array',
            'version' => 'integer',
        ]);
    }

    /**
     * @return HasMany<WorkflowStep>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(WorkflowStep::class, 'workflow_id');
    }

    /**
     * @return HasMany<WorkflowTransition>
     */
    public function transitions(): HasMany
    {
        return $this->hasMany(WorkflowTransition::class, 'workflow_id');
    }

    /**
     * @return HasMany<WorkflowCondition>
     */
    public function conditions(): HasMany
    {
        return $this->hasMany(WorkflowCondition::class, 'workflow_id');
    }

    /**
     * @return HasMany<WorkflowInstance>
     */
    public function instances(): HasMany
    {
        return $this->hasMany(WorkflowInstance::class, 'workflow_id');
    }

    /**
     * @return BelongsTo<WorkflowStep, Workflow>
     */
    public function startStep(): BelongsTo
    {
        return $this->belongsTo(WorkflowStep::class, 'start_step_id');
    }
}
