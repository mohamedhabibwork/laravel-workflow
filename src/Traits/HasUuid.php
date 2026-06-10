<?php

namespace HFlow\LaravelWorkflow\Traits;

use Illuminate\Support\Str;

trait HasUuid
{
    /**
     * Generate a UUID for new models when one has not been provided.
     */
    protected static function bootHasUuid(): void
    {
        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid7();
            }
        });
    }
}
