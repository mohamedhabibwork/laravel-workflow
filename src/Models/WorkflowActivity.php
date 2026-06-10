<?php

namespace HFlow\LaravelWorkflow\Models;

use HFlow\LaravelWorkflow\Enums\ActivityStatus;
use HFlow\LaravelWorkflow\Traits\HasAudit;
use HFlow\LaravelWorkflow\Traits\HasUuid;
use HFlow\LaravelWorkflow\Traits\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int|null $tenant_id
 * @property int $workflow_instance_id
 * @property int|null $step_instance_id
 * @property string $name
 * @property string $handler
 * @property string|null $task_queue
 * @property ActivityStatus $status
 * @property array<string, mixed>|null $input
 * @property array<string, mixed>|null $result
 * @property string|null $error
 * @property int $attempt
 * @property int $max_attempts
 * @property string|null $async_token
 */
class WorkflowActivity extends Model
{
    use HasAudit;
    use HasFactory;
    use HasUuid;
    use SoftDeletes;

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    protected $fillable = [
        'tenant_id',
        'workflow_instance_id',
        'step_instance_id',
        'name',
        'handler',
        'task_queue',
        'status',
        'input',
        'result',
        'error',
        'attempt',
        'max_attempts',
        'async_token',
        'available_at',
        'started_at',
        'heartbeat_at',
        'schedule_to_close_timeout_at',
        'start_to_close_timeout_at',
        'completed_at',
    ];

    protected $casts = [
        'status' => ActivityStatus::class,
        'input' => 'array',
        'result' => 'array',
        'attempt' => 'integer',
        'max_attempts' => 'integer',
        'available_at' => 'datetime',
        'started_at' => 'datetime',
        'heartbeat_at' => 'datetime',
        'schedule_to_close_timeout_at' => 'datetime',
        'start_to_close_timeout_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function getTable(): string
    {
        return config('workflow.table_prefix', 'workflow_').'workflow_activities';
    }

    /**
     * @return BelongsTo<WorkflowInstance, $this>
     */
    public function workflowInstance(): BelongsTo
    {
        return $this->belongsTo(WorkflowInstance::class);
    }

    /**
     * @return BelongsTo<WorkflowStepInstance, $this>
     */
    public function stepInstance(): BelongsTo
    {
        return $this->belongsTo(WorkflowStepInstance::class);
    }
}
