<?php

namespace HFlow\LaravelWorkflow\Models;

use HFlow\LaravelWorkflow\Enums\ActorType;
use HFlow\LaravelWorkflow\Enums\HistoryEvent;
use HFlow\LaravelWorkflow\Traits\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int $workflow_instance_id
 * @property int|null $step_instance_id
 * @property int|null $from_step_id
 * @property int|null $to_step_id
 * @property string|null $action_code
 * @property HistoryEvent $event
 * @property int|null $actor_id
 * @property ActorType $actor_type
 * @property string|null $comment
 * @property array<string, mixed>|null $metadata
 */
class WorkflowHistory extends Model
{
    public $timestamps = false; // Manually handled

    protected $fillable = [
        'workflow_instance_id',
        'step_instance_id',
        'from_step_id',
        'to_step_id',
        'action_code',
        'event',
        'actor_id',
        'actor_type',
        'comment',
        'metadata',
        'performed_at',
        'created_at',
    ];

    protected $casts = [
        'event' => HistoryEvent::class,
        'actor_type' => ActorType::class,
        'metadata' => 'array',
        'performed_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    /**
     * Fill UUID and timestamp fields for append-only history records.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid7();
            }
            if (empty($model->created_at)) {
                $model->created_at = now();
            }
            if (empty($model->performed_at)) {
                $model->performed_at = now();
            }
        });
    }

    /**
     * Apply tenant filtering through the parent workflow instance when enabled.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    /**
     * Get the configured workflow histories table name.
     */
    public function getTable(): string
    {
        return config('workflow.table_prefix', 'workflow_').'workflow_histories';
    }

    /**
     * Get the workflow instance that owns this history entry.
     *
     * @return BelongsTo<WorkflowInstance, $this>
     */
    public function workflowInstance(): BelongsTo
    {
        return $this->belongsTo(WorkflowInstance::class);
    }

    /**
     * Get the runtime step instance associated with this event, when available.
     *
     * @return BelongsTo<WorkflowStepInstance, $this>
     */
    public function stepInstance(): BelongsTo
    {
        return $this->belongsTo(WorkflowStepInstance::class);
    }

    /**
     * Get the source step for transition-style history events.
     *
     * @return BelongsTo<WorkflowStep, $this>
     */
    public function fromStep(): BelongsTo
    {
        return $this->belongsTo(WorkflowStep::class, 'from_step_id');
    }

    /**
     * Get the destination step for transition-style history events.
     *
     * @return BelongsTo<WorkflowStep, $this>
     */
    public function toStep(): BelongsTo
    {
        return $this->belongsTo(WorkflowStep::class, 'to_step_id');
    }
}
