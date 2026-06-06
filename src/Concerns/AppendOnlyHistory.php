<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Concerns;

use HFlow\LaravelWorkflow\Exceptions\AppendOnlyViolationException;
use Illuminate\Database\Eloquent\Model;

/**
 * Locks down the model so that it can only be INSERTed, never updated or
 * deleted. Use ONLY on append-only models (WorkflowHistory).
 *
 * The boot method hooks into Eloquent's `updating` and `deleting` events
 * to throw AppendOnlyViolationException, which is a LogicException
 * (programmer error).
 */
trait AppendOnlyHistory
{
    public static function bootAppendOnlyHistory(): void
    {
        static::updating(function (Model $model): void {
            throw new AppendOnlyViolationException(
                sprintf('Cannot update append-only %s (id=%s).', $model::class, (string) $model->getKey()),
            );
        });

        static::deleting(function (Model $model): void {
            throw new AppendOnlyViolationException(
                sprintf('Cannot delete append-only %s (id=%s).', $model::class, (string) $model->getKey()),
            );
        });
    }
}
