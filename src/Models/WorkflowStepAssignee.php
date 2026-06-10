<?php

namespace HFlow\LaravelWorkflow\Models;

use HFlow\LaravelWorkflow\Enums\AssigneeType;
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
 * @property AssigneeType $assignee_type
 * @property string|null $assignee_value
 * @property string|null $custom_resolver
 * @property int $sort_order
 */
class WorkflowStepAssignee extends Model
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
        'assignee_type',
        'assignee_value',
        'custom_resolver',
        'sort_order',
    ];

    protected $casts = [
        'assignee_type' => AssigneeType::class,
        'sort_order' => 'integer',
    ];

    /**
     * Get the configured workflow step assignees table name.
     */
    public function getTable(): string
    {
        return config('workflow.table_prefix', 'workflow_').'workflow_step_assignees';
    }

    /**
     * Get the step that owns this assignee rule.
     *
     * @return BelongsTo<WorkflowStep, $this>
     */
    public function step(): BelongsTo
    {
        return $this->belongsTo(WorkflowStep::class, 'step_id');
    }
}
