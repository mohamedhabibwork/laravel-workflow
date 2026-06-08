<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

trait HasUuid
{
    /**
     * Boot the trait and add a creating listener to generate a UUID if not already set.
     * This ensures that every model using this trait will have a UUID assigned upon creation.
     * Note: This method should be called in the boot method of the model using this trait.
     *
     * @see https://laravel.com/docs/eloquent#events
     */
    public static function bootHasUuid(): void
    {
        static::creating(function (Model $model): void {
            if (empty($model->getAttribute('uuid'))) {
                $model->setAttribute('uuid', (string) Str::uuid7());
            }
        });
    }
}
