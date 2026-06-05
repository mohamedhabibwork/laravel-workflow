<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Facades;

use HFlow\LaravelWorkflow\Contracts\WorkflowEngine;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \HFlow\LaravelWorkflow\Models\Workflow define(string $key, array $definition): \HFlow\LaravelWorkflow\Models\Workflow
 * @method static \HFlow\LaravelWorkflow\Models\Workflow activate(string $key): \HFlow\LaravelWorkflow\Models\Workflow
 * @method static \HFlow\LaravelWorkflow\Models\WorkflowInstance start(string $key, mixed $subject, array $context = []): \HFlow\LaravelWorkflow\Models\WorkflowInstance
 * @method static \HFlow\LaravelWorkflow\Actions\ActionSet availableActions(string $instanceId, ?string $stepKey = null, mixed $user = null): \HFlow\LaravelWorkflow\Actions\ActionSet
 * @method static \HFlow\LaravelWorkflow\Models\WorkflowInstance perform(string $instanceId, string $actionKey, mixed $user, array $payload = []): \HFlow\LaravelWorkflow\Models\WorkflowInstance
 * @method static \HFlow\LaravelWorkflow\Models\WorkflowInstance hold(string $instanceId, ?string $reason = null, mixed $actor = null): \HFlow\LaravelWorkflow\Models\WorkflowInstance
 * @method static \HFlow\LaravelWorkflow\Models\WorkflowInstance resume(string $instanceId, mixed $actor = null): \HFlow\LaravelWorkflow\Models\WorkflowInstance
 * @method static \HFlow\LaravelWorkflow\Models\WorkflowInstance cancel(string $instanceId, ?string $reason = null, mixed $actor = null): \HFlow\LaravelWorkflow\Models\WorkflowInstance
 * @method static \HFlow\LaravelWorkflow\Models\WorkflowInstance skipStep(string $instanceId, string $stepKey, ?string $reason = null, mixed $actor = null): \HFlow\LaravelWorkflow\Models\WorkflowInstance
 * @method static \HFlow\LaravelWorkflow\Models\WorkflowInstance returnToStep(string $instanceId, string $currentStepKey, string $targetStepKey, ?string $reason = null, mixed $actor = null): \HFlow\LaravelWorkflow\Models\WorkflowInstance
 * @method static \HFlow\LaravelWorkflow\Engines\HistoryRecorder history()
 * @method static \HFlow\LaravelWorkflow\Models\WorkflowInstance currentStep(string $instanceId): \HFlow\LaravelWorkflow\Models\WorkflowInstance
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
