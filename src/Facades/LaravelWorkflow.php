<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Facades;

use HFlow\LaravelWorkflow\Contracts\WorkflowEngine;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \HFlow\LaravelWorkflow\Models\Workflow define(string $key, array $definition): \HFlow\LaravelWorkflow\Models\Workflow
 * @method static \HFlow\LaravelWorkflow\Models\Workflow activate(\HFlow\LaravelWorkflow\Models\Workflow|string $workflow): \HFlow\LaravelWorkflow\Models\Workflow
 * @method static \Illuminate\Support\Collection versions(\HFlow\LaravelWorkflow\Models\Workflow|string $workflow): \Illuminate\Support\Collection
 * @method static \HFlow\LaravelWorkflow\Models\Workflow createNewVersion(\HFlow\LaravelWorkflow\Models\Workflow $workflow, array $overrides = []): \HFlow\LaravelWorkflow\Models\Workflow
 * @method static \HFlow\LaravelWorkflow\Models\WorkflowInstance start(\HFlow\LaravelWorkflow\Models\Workflow|string $workflow, \Illuminate\Database\Eloquent\Model $subject, array $context = [], mixed $initiator = null): \HFlow\LaravelWorkflow\Models\WorkflowInstance
 * @method static \HFlow\LaravelWorkflow\Models\WorkflowStepInstance|\Illuminate\Support\Collection currentStep(\HFlow\LaravelWorkflow\Models\WorkflowInstance $instance): \HFlow\LaravelWorkflow\Models\WorkflowStepInstance|\Illuminate\Support\Collection
 * @method static \HFlow\LaravelWorkflow\Actions\ActionSet availableActions(\HFlow\LaravelWorkflow\Models\WorkflowInstance $instance, mixed $user = null): \HFlow\LaravelWorkflow\Actions\ActionSet
 * @method static \HFlow\LaravelWorkflow\Models\WorkflowInstance perform(\HFlow\LaravelWorkflow\Models\WorkflowInstance $instance, string $actionCode, mixed $user = null, ?array $payload = null): \HFlow\LaravelWorkflow\Models\WorkflowInstance
 * @method static \HFlow\LaravelWorkflow\Models\WorkflowInstance skip(\HFlow\LaravelWorkflow\Models\WorkflowInstance $instance, mixed $user = null, ?string $comment = null): \HFlow\LaravelWorkflow\Models\WorkflowInstance
 * @method static \HFlow\LaravelWorkflow\Models\WorkflowInstance return(\HFlow\LaravelWorkflow\Models\WorkflowInstance $instance, \HFlow\LaravelWorkflow\Models\WorkflowStep|string|null $targetStep = null, mixed $user = null, ?string $comment = null): \HFlow\LaravelWorkflow\Models\WorkflowInstance
 * @method static \HFlow\LaravelWorkflow\Models\WorkflowInstance retry(\HFlow\LaravelWorkflow\Models\WorkflowInstance $instance, mixed $user = null, ?string $comment = null): \HFlow\LaravelWorkflow\Models\WorkflowInstance
 * @method static \HFlow\LaravelWorkflow\Models\WorkflowInstance hold(\HFlow\LaravelWorkflow\Models\WorkflowInstance $instance, mixed $user = null, ?string $comment = null): \HFlow\LaravelWorkflow\Models\WorkflowInstance
 * @method static \HFlow\LaravelWorkflow\Models\WorkflowInstance resume(\HFlow\LaravelWorkflow\Models\WorkflowInstance $instance, mixed $user = null): \HFlow\LaravelWorkflow\Models\WorkflowInstance
 * @method static \HFlow\LaravelWorkflow\Models\WorkflowInstance cancel(\HFlow\LaravelWorkflow\Models\WorkflowInstance $instance, mixed $user = null, ?string $comment = null): \HFlow\LaravelWorkflow\Models\WorkflowInstance
 * @method static \Illuminate\Support\Collection history(\HFlow\LaravelWorkflow\Models\WorkflowInstance $instance, ?int $limit = null, ?string $event = null): \Illuminate\Support\Collection
 *
 * @see \HFlow\LaravelWorkflow\Engines\WorkflowEngine
 */
class LaravelWorkflow extends Facade
{
    /**
     * The container binding the facade resolves to.
     *
     * The accessor returns the FQCN of the engine implementation. The
     * service container will auto-resolve it and inject its dependencies.
     * If the host binds a different implementation, that binding wins.
     */
    protected static function getFacadeAccessor(): string
    {
        return WorkflowEngine::class;
    }
}
