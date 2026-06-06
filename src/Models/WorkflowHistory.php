<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Models;

use HFlow\LaravelWorkflow\Concerns\AppendOnlyHistory;
use HFlow\LaravelWorkflow\Enums\ActorType;
use HFlow\LaravelWorkflow\Enums\HistoryEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only audit log row. ONE direct INSERT per event; no updates.
 *
 * This model:
 *  - Does NOT use SoftDeletes
 *  - Has `$timestamps = false` and provides its own `performed_at` and
 *    `created_at` columns (set by the HistoryRecorder on insert)
 *  - Uses the AppendOnlyHistory trait which throws on any update/delete
 *  - Does NOT use HasUuid — it uses bigIncrements id (the HistoryRecorder
 *    assigns the id via the DB) and a separate `uuid` column for
 *    public reference.
 *
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
 * @property \Illuminate\Support\Carbon $performed_at
 * @property \Illuminate\Support\Carbon $created_at
 */
final class WorkflowHistory extends Model
{
    use AppendOnlyHistory;
    use \HFlow\LaravelWorkflow\Concerns\HasUuid;

    /**
     * Disable Eloquent's automatic timestamp management.
     * History rows have `performed_at` and `created_at`, but those are
     * written by the HistoryRecorder, not by Eloquent.
     */
    public $timestamps = false;

    protected function tableName(): string
    {
        return 'workflow_histories';
    }

    public function getTable(): string
    {
        $prefix = (string) config('workflow.table_prefix', 'workflow_');

        return $prefix.'workflow_histories';
    }

    protected $fillable = [
        'uuid', 'workflow_instance_id', 'step_instance_id',
        'from_step_id', 'to_step_id', 'action_code', 'event',
        'actor_id', 'actor_type', 'comment', 'metadata',
        'performed_at', 'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event' => HistoryEvent::class,
            'actor_type' => ActorType::class,
            'metadata' => 'array',
            'performed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<WorkflowInstance, WorkflowHistory>
     */
    public function instance(): BelongsTo
    {
        return $this->belongsTo(WorkflowInstance::class, 'workflow_instance_id');
    }

    /**
     * @return BelongsTo<WorkflowStepInstance, WorkflowHistory>
     */
    public function stepInstance(): BelongsTo
    {
        return $this->belongsTo(WorkflowStepInstance::class, 'step_instance_id');
    }

    /**
     * @return BelongsTo<WorkflowStep, WorkflowHistory>
     */
    public function fromStep(): BelongsTo
    {
        return $this->belongsTo(WorkflowStep::class, 'from_step_id');
    }

    /**
     * @return BelongsTo<WorkflowStep, WorkflowHistory>
     */
    public function toStep(): BelongsTo
    {
        return $this->belongsTo(WorkflowStep::class, 'to_step_id');
    }
}
