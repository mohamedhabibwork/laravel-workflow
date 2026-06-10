<?php

namespace HFlow\LaravelWorkflow\Traits;

use HFlow\LaravelWorkflow\Builders\WorkflowBuilder;
use HFlow\LaravelWorkflow\Enums\InstanceStatus;
use HFlow\LaravelWorkflow\LaravelWorkflow;
use HFlow\LaravelWorkflow\Models\WorkflowInstance;
use HFlow\LaravelWorkflow\Models\WorkflowStepAction;
use HFlow\LaravelWorkflow\Services\WorkflowEngine;
use Illuminate\Contracts\Auth\Authenticatable as User;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Collection;

trait HasWorkflow
{
    /**
     * Build a fluent workflow API for the model.
     */
    public function workflow(?WorkflowInstance $instance = null): WorkflowBuilder
    {
        $builderClass = config('workflow.classes.workflow_builder', WorkflowBuilder::class);

        if (! is_string($builderClass) || ! class_exists($builderClass) || ! is_subclass_of($builderClass, WorkflowBuilder::class)) {
            $builderClass = WorkflowBuilder::class;
        }

        return app()->makeWith($builderClass, [
            'subject' => $this,
            'workflow' => app(LaravelWorkflow::class),
            'instance' => $instance,
        ]);
    }

    /**
     * Get all workflow instances for the model.
     *
     * @return MorphMany<WorkflowInstance, $this>
     */
    public function workflowInstances(): MorphMany
    {
        return $this->morphMany(WorkflowInstance::class, 'subject');
    }

    /**
     * Get the most recent active, held, pending, or failed workflow instance.
     */
    public function currentWorkflowInstance(): ?WorkflowInstance
    {
        return $this->workflowInstances()
            ->whereIn('status', [
                InstanceStatus::Pending->value,
                InstanceStatus::InProgress->value,
                InstanceStatus::OnHold->value,
                InstanceStatus::Failed->value,
            ])
            ->latest()
            ->first();
    }

    /**
     * Start a workflow for the model.
     *
     * @param  array<string, mixed>  $context
     */
    public function startWorkflow(string $workflowCode, array $context = [], ?User $user = null): WorkflowInstance
    {
        return $this->workflow()->start($workflowCode, $context, $user);
    }

    /**
     * Start a workflow for the model with runtime options such as identity, memo, search attributes, delay, and timeouts.
     *
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $options
     */
    public function startWorkflowWithOptions(string $workflowCode, array $context = [], ?User $user = null, array $options = []): WorkflowInstance
    {
        return $this->workflow()->startWithOptions($workflowCode, $context, $user, $options);
    }

    /**
     * Perform an action on the model's selected or current workflow instance.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws \Exception
     */
    public function performWorkflowAction(string $actionCode, ?User $user = null, array $payload = [], ?WorkflowInstance $instance = null): self
    {
        $this->workflow($instance)->performAction($actionCode, $user, $payload);

        return $this;
    }

    /**
     * Resolve actions available on the model's selected or current workflow instance.
     *
     * @return Collection<int, WorkflowStepAction>
     *
     * @throws \Exception
     */
    public function workflowActions(?User $user = null, ?WorkflowInstance $instance = null): Collection
    {
        return $this->workflow($instance)->availableActions($user);
    }

    /**
     * Get the workflow engine used by model workflow helpers.
     */
    public function workflowEngine(): WorkflowEngine
    {
        return $this->workflow()->engine();
    }

    /**
     * Replace the workflow engine used by model workflow helpers.
     */
    public function setWorkflowEngine(WorkflowEngine $engine): self
    {
        $this->workflow()->setEngine($engine);

        return $this;
    }

    /**
     * Replace the workflow engine using a fluent model-friendly method name.
     */
    public function useWorkflowEngine(WorkflowEngine $engine): self
    {
        return $this->setWorkflowEngine($engine);
    }
}
