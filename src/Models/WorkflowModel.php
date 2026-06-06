<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Base class for all workflow Eloquent models.
 *
 * Provides a configurable table name that honors the
 * `workflow.table_prefix` config value. Subclasses declare their
 * logical table name via `tableName()` and the prefix is prepended
 * at runtime.
 *
 * Conventions enforced by this base:
 *  - Table name is `config('workflow.table_prefix') . tableName()`
 *  - UUID v4 primary key (`id` is BIGINT, `uuid` is the public key)
 *  - Soft delete via `is_deleted`/`deleted_at` columns
 *  - Audit columns (`created_by`, `updated_by`, `deleted_by`)
 *
 * The `WorkflowHistory` model does NOT extend this base because it has
 * different conventions (append-only, no soft delete, no updated_at).
 */
abstract class WorkflowModel extends Model
{
    use \HFlow\LaravelWorkflow\Concerns\HasUuid;
    use \Illuminate\Database\Eloquent\SoftDeletes;
    use \HFlow\LaravelWorkflow\Concerns\HasWorkflowTimestamps;

    /**
     * The column used to indicate soft deletion.
     */
    public const DELETED_AT = 'deleted_at';

    /**
     * Logical (un-prefixed) table name. Subclasses MUST override.
     */
    abstract protected function tableName(): string;

    /**
     * Get the table associated with the model, prefixed per config.
     */
    public function getTable(): string
    {
        $prefix = (string) config('workflow.table_prefix', 'workflow_');

        return $prefix.$this->tableName();
    }

    /**
     * Cast deleted_at as datetime (Eloquent's SoftDeletes expects this).
     * Subclasses MUST override and return public method (parent signature).
     *
     * @return array<string, string>
     */
    public function casts(): array
    {
        return [
            self::DELETED_AT => 'datetime',
        ];
    }
}
