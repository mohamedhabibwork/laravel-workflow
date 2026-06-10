<?php

namespace HFlow\LaravelWorkflow\Builders;

use Carbon\CarbonInterface;
use HFlow\LaravelWorkflow\LaravelWorkflow;
use HFlow\LaravelWorkflow\Models\WorkflowInstance;
use HFlow\LaravelWorkflow\Models\WorkflowStepAction;
use HFlow\LaravelWorkflow\Models\WorkflowTimer;
use HFlow\LaravelWorkflow\Services\WorkflowEngine;
use Illuminate\Contracts\Auth\Authenticatable as User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Fluent workflow API scoped to a single Eloquent subject model.
 */
class WorkflowBuilder
{
    /**
     * Create a builder for a workflow subject model.
     */
    public function __construct(
        protected Model $subject,
        protected LaravelWorkflow $workflow,
        protected ?WorkflowInstance $instance = null,
    ) {}

    /**
     * Get the subject model this builder operates on.
     */
    public function subject(): Model
    {
        return $this->subject;
    }

    /**
     * Get the workflow instance explicitly set on this builder or the current subject instance.
     */
    public function instance(): ?WorkflowInstance
    {
        return $this->instance ?? $this->current();
    }

    /**
     * Set the workflow instance this builder should operate on.
     */
    public function forInstance(WorkflowInstance $instance): self
    {
        $this->instance = $instance;

        return $this;
    }

    /**
     * Get the current active workflow instance for the subject.
     */
    public function current(): ?WorkflowInstance
    {
        if (! method_exists($this->subject, 'currentWorkflowInstance')) {
            return null;
        }

        return $this->subject->currentWorkflowInstance();
    }

    /**
     * Start a workflow for the subject model.
     *
     * @param  array<string, mixed>  $context
     */
    public function start(string $workflowCode, array $context = [], ?User $user = null): WorkflowInstance
    {
        return $this->startWithOptions($workflowCode, $context, $user);
    }

    /**
     * Start a workflow for the subject model with runtime options.
     *
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $options
     */
    public function startWithOptions(string $workflowCode, array $context = [], ?User $user = null, array $options = []): WorkflowInstance
    {
        $this->instance = $this->workflow->startWithOptions($workflowCode, $this->subject, $context, $user, $options);

        return $this->instance;
    }

    /**
     * Perform an action on the selected or current workflow instance.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws \Exception
     */
    public function performAction(string $actionCode, ?User $user = null, array $payload = [], ?WorkflowInstance $instance = null): self
    {
        $workflowInstance = $instance ?? $this->instance();

        if (! $workflowInstance) {
            throw new \Exception('No workflow instance is available for this subject.');
        }

        $this->workflow->performAction($workflowInstance, $actionCode, $user, $payload);
        $this->instance = $workflowInstance->fresh();

        return $this;
    }

    /**
     * Resolve actions available on the selected or current workflow instance.
     *
     * @return Collection<int, WorkflowStepAction>
     *
     * @throws \Exception
     */
    public function availableActions(?User $user = null, ?WorkflowInstance $instance = null): Collection
    {
        $workflowInstance = $instance ?? $this->instance();

        if (! $workflowInstance) {
            throw new \Exception('No workflow instance is available for this subject.');
        }

        return $this->workflow->getAvailableActions($workflowInstance, $user);
    }

    /**
     * Deliver a signal to the selected or current workflow instance.
     *
     * @param  array<string, mixed>  $payload
     */
    public function signal(string $signal, array $payload = [], ?User $user = null, ?WorkflowInstance $instance = null): self
    {
        $workflowInstance = $this->requireInstance($instance);

        $this->workflow->signal($workflowInstance, $signal, $payload, $user);
        $this->instance = $workflowInstance->fresh();

        return $this;
    }

    /**
     * Execute a validated workflow update.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function update(string $update, array $payload = [], ?User $user = null, ?WorkflowInstance $instance = null): array
    {
        return $this->workflow->update($this->requireInstance($instance), $update, $payload, $user);
    }

    /**
     * Execute a read-only workflow query.
     *
     * @param  array<string, mixed>  $payload
     */
    public function query(string $query = 'state', array $payload = [], ?User $user = null, ?WorkflowInstance $instance = null): mixed
    {
        return $this->workflow->query($this->requireInstance($instance), $query, $payload, $user);
    }

    /**
     * Cancel the selected or current workflow instance.
     */
    public function cancel(?string $reason = null, ?User $user = null, ?WorkflowInstance $instance = null): self
    {
        $workflowInstance = $this->requireInstance($instance);

        $this->workflow->cancel($workflowInstance, $reason, $user);
        $this->instance = $workflowInstance->fresh();

        return $this;
    }

    /**
     * Terminate the selected or current workflow instance.
     */
    public function terminate(?string $reason = null, ?User $user = null, ?WorkflowInstance $instance = null): self
    {
        $workflowInstance = $this->requireInstance($instance);

        $this->workflow->terminate($workflowInstance, $reason, $user);
        $this->instance = $workflowInstance->fresh();

        return $this;
    }

    /**
     * Retry the latest failed step in the selected or current workflow instance.
     */
    public function retry(?User $user = null, ?WorkflowInstance $instance = null): self
    {
        $workflowInstance = $this->requireInstance($instance);

        $this->workflow->retry($workflowInstance, $user);
        $this->instance = $workflowInstance->fresh();

        return $this;
    }

    /**
     * Continue the selected or current workflow as a fresh run.
     *
     * @param  array<string, mixed>  $context
     */
    public function continueAsNew(array $context = [], ?User $user = null, ?WorkflowInstance $instance = null): WorkflowInstance
    {
        $this->instance = $this->workflow->continueAsNew($this->requireInstance($instance), $context, $user);

        return $this->instance;
    }

    /**
     * Schedule a durable timer for the selected or current workflow instance.
     *
     * @param  array<string, mixed>  $payload
     */
    public function scheduleTimer(string $name, CarbonInterface $dueAt, array $payload = [], ?string $handler = null, ?User $user = null, ?WorkflowInstance $instance = null): WorkflowTimer
    {
        return $this->workflow->scheduleTimer($this->requireInstance($instance), $name, $dueAt, $payload, $handler, $user);
    }

    /**
     * Merge workflow visibility search attributes.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function upsertSearchAttributes(array $attributes, ?User $user = null, ?WorkflowInstance $instance = null): self
    {
        $this->workflow->upsertSearchAttributes($this->requireInstance($instance), $attributes, $user);

        return $this;
    }

    /**
     * Get the workflow engine used by this builder.
     */
    public function getEngine(): WorkflowEngine
    {
        return $this->workflow->getEngine();
    }

    /**
     * Get the workflow engine using a terse builder-friendly method name.
     */
    public function engine(): WorkflowEngine
    {
        return $this->getEngine();
    }

    /**
     * Replace the workflow engine used by this builder.
     */
    public function setEngine(WorkflowEngine $engine): self
    {
        $this->workflow->setEngine($engine);

        return $this;
    }

    /**
     * Replace the workflow engine using a fluent builder-friendly method name.
     */
    public function useEngine(WorkflowEngine $engine): self
    {
        return $this->setEngine($engine);
    }

    /**
     * Resolve an explicit, builder-selected, or current workflow instance.
     *
     * @throws \Exception
     */
    protected function requireInstance(?WorkflowInstance $instance = null): WorkflowInstance
    {
        $workflowInstance = $instance ?? $this->instance();

        if (! $workflowInstance) {
            throw new \Exception('No workflow instance is available for this subject.');
        }

        return $workflowInstance;
    }
}
