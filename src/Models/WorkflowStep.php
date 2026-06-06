<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Models;

use HFlow\LaravelWorkflow\Enums\AuthorizationMode;
use HFlow\LaravelWorkflow\Enums\MatchMode;
use HFlow\LaravelWorkflow\Enums\StepType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A node (step) within a workflow.
 *
 * @property int $id
 * @property string $uuid
 * @property int|null $tenant_id
 * @property int $workflow_id
 * @property string $name
 * @property string $code
 * @property string|null $description
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
 */
final class WorkflowStep extends WorkflowModel
{
    protected function tableName(): string
    {
        return 'steps';
    }

    protected $fillable = [
        'uuid', 'tenant_id', 'workflow_id', 'name', 'code', 'description',
        'type', 'position', 'authorization_mode', 'match_mode',
        'custom_authorizer', 'handler', 'is_skippable', 'is_returnable',
        'sla_seconds', 'config',
        'is_deleted', 'deleted_at', 'created_by', 'updated_by', 'deleted_by',
    ];

    /**
     * @return array<string, string>
     */
    public function casts(): array
    {
        return array_merge(parent::casts(), [
            'type' => StepType::class,
            'authorization_mode' => AuthorizationMode::class,
            'match_mode' => MatchMode::class,
            'is_skippable' => 'boolean',
            'is_returnable' => 'boolean',
            'position' => 'integer',
            'sla_seconds' => 'integer',
            'config' => 'array',
        ]);
    }

    /**
     * @return BelongsTo<Workflow, WorkflowStep>
     */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class, 'workflow_id');
    }

    /**
     * @return HasMany<WorkflowStepAssignee>
     */
    public function assignees(): HasMany
    {
        return $this->hasMany(WorkflowStepAssignee::class, 'step_id');
    }

    /**
     * @return HasMany<WorkflowStepAction>
     */
    public function actions(): HasMany
    {
        return $this->hasMany(WorkflowStepAction::class, 'step_id');
    }

    /**
     * @return HasMany<WorkflowTransition>
     */
    public function outgoingTransitions(): HasMany
    {
        return $this->hasMany(WorkflowTransition::class, 'from_step_id');
    }

    /**
     * @return HasMany<WorkflowTransition>
     */
    public function incomingTransitions(): HasMany
    {
        return $this->hasMany(WorkflowTransition::class, 'to_step_id');
    }

    /**
     * @return HasMany<WorkflowStepInstance>
     */
    public function instances(): HasMany
    {
        return $this->hasMany(WorkflowStepInstance::class, 'step_id');
    }
}
