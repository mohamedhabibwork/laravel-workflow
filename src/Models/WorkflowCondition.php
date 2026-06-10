<?php

namespace HFlow\LaravelWorkflow\Models;

use HFlow\LaravelWorkflow\Enums\ConditionKind;
use HFlow\LaravelWorkflow\Traits\HasAudit;
use HFlow\LaravelWorkflow\Traits\HasUuid;
use HFlow\LaravelWorkflow\Traits\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int|null $workflow_id
 * @property string $name
 * @property string $code
 * @property ConditionKind $kind
 * @property array<string, mixed>|null $expression
 * @property string|null $evaluator
 */
class WorkflowCondition extends Model
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
        'kind',
        'expression',
        'evaluator',
    ];

    protected $casts = [
        'kind' => ConditionKind::class,
        'expression' => 'array',
    ];

    /**
     * Get the configured workflow conditions table name.
     */
    public function getTable(): string
    {
        return config('workflow.table_prefix', 'workflow_').'workflow_conditions';
    }

    /**
     * Get the workflow definition that owns this condition.
     *
     * @return BelongsTo<Workflow, $this>
     */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }
}
