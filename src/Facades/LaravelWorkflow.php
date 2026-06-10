<?php

namespace HFlow\LaravelWorkflow\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see     \HFlow\LaravelWorkflow\LaravelWorkflow
 *
 * @mixin   \HFlow\LaravelWorkflow\LaravelWorkflow
 */
class LaravelWorkflow extends Facade
{
    /**
     * Resolve the service container binding behind the facade.
     */
    protected static function getFacadeAccessor(): string
    {
        return \HFlow\LaravelWorkflow\LaravelWorkflow::class;
    }
}
