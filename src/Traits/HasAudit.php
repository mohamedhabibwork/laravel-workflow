<?php

namespace HFlow\LaravelWorkflow\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

trait HasAudit
{
    /**
     * Populate audit columns from the authenticated user during model lifecycle events.
     */
    protected static function bootHasAudit(): void
    {
        static::creating(function (Model $model) {
            if (Auth::check() && empty($model->getAttribute('created_by'))) {
                $model->setAttribute('created_by', Auth::id());
            }
        });

        static::updating(function (Model $model) {
            if (Auth::check()) {
                $model->setAttribute('updated_by', Auth::id());
            }
        });

        static::deleting(function (Model $model) {
            if (Auth::check() && method_exists($model, 'isForceDeleting') && ! $model->isForceDeleting()) {
                $model->setAttribute('deleted_by', Auth::id());
                $model->setAttribute('is_deleted', true);
                $model->save();
            }
        });
    }
}
