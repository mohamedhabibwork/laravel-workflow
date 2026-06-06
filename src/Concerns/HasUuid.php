<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

trait HasUuid
{
    public static function bootHasUuid(): void
    {
        static::creating(function (Model $model): void {
            $key = $model->getKeyName();
            if (empty($model->getAttribute($key))) {
                $model->setAttribute($key, (string) Str::uuid());
            }
            if (empty($model->getAttribute('uuid'))) {
                $model->setAttribute('uuid', (string) Str::uuid());
            }
        });
    }
}
