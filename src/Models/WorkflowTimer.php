<?php

namespace HFlow\LaravelWorkflow\Models;

use HFlow\LaravelWorkflow\Enums\TimerStatus;
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
 * @property string $name
 * @property TimerStatus $status
 * @property array<string, mixed>|null $payload
 * @property string|null $handler
 */
class WorkflowTimer extends Model
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
        'tenant_id',
        'workflow_instance_id',
        'name',
        'status',
        'payload',
        'handler',
        'due_at',
        'fired_at',
    ];

    protected $casts = [
        'status' => TimerStatus::class,
        'payload' => 'array',
        'due_at' => 'datetime',
        'fired_at' => 'datetime',
    ];

    /**
     * Get the configured workflow timers table name.
     */
    public function getTable(): string
    {
        return config('workflow.table_prefix', 'workflow_').'workflow_timers';
    }

    /**
     * Get the workflow instance that owns this timer.
     *
     * @return BelongsTo<WorkflowInstance, $this>
     */
    public function workflowInstance(): BelongsTo
    {
        return $this->belongsTo(WorkflowInstance::class);
    }
}
