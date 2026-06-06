<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Ensures created_at and updated_at are set explicitly.
 * Eloquent's default timestamp behavior is enabled, but this trait
 * makes the intent explicit and ensures they are set even if the
 * model is saved via ::create() rather than ::save().
 */
trait HasWorkflowTimestamps
{
    public static function bootHasWorkflowTimestamps(): void
    {
        static::creating(function (Model $model): void {
            $now = now();
            if (empty($model->getAttribute('created_at'))) {
                $model->setAttribute('created_at', $now);
            }
            if (empty($model->getAttribute('updated_at'))) {
                $model->setAttribute('updated_at', $now);
            }
        });

        static::updating(function (Model $model): void {
            $model->setAttribute('updated_at', now());
        });
    }
}
