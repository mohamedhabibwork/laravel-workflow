<?php

namespace HFlow\LaravelWorkflow;

use Carbon\CarbonInterface;
use HFlow\LaravelWorkflow\Enums\WorkflowStatus;
use HFlow\LaravelWorkflow\Models\Workflow;
use HFlow\LaravelWorkflow\Models\WorkflowActivity;
use HFlow\LaravelWorkflow\Models\WorkflowInstance;
use HFlow\LaravelWorkflow\Models\WorkflowStepAction;
use HFlow\LaravelWorkflow\Models\WorkflowTimer;
use HFlow\LaravelWorkflow\Services\ActionResolver;
use HFlow\LaravelWorkflow\Services\ActivityService;
use HFlow\LaravelWorkflow\Services\AttributeWorkflowRegistrar;
use HFlow\LaravelWorkflow\Services\WorkflowEngine;
use HFlow\LaravelWorkflow\Services\WorkflowService;
use Illuminate\Contracts\Auth\Authenticatable as User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Main package class providing the public API for workflow operations.
 * This class is designed to be used via the LaravelWorkflow facade, which resolves to this class in the service container.
 * It delegates workflow operations to the underlying services, keeping the public API clean and focused on common use cases.
 */
class LaravelWorkflow
{
    protected AttributeWorkflowRegistrar $attributeRegistrar;

    /**
     * Create the package facade target with the services used by the public API.
     */
    public function __construct(
        protected WorkflowEngine $engine,
        protected WorkflowService $service,
        protected ActionResolver $actionResolver,
        protected ?ActivityService $activityService = null,
        ?AttributeWorkflowRegistrar $attributeRegistrar = null,
    ) {
        $this->attributeRegistrar = $attributeRegistrar ?? new AttributeWorkflowRegistrar($this->service);
        $this->activityService ??= new ActivityService;
    }

    /**
     * Get the workflow engine used by the package API.
     */
    public function getEngine(): WorkflowEngine
    {
        return $this->engine;
    }

    /**
     * Get the workflow engine using a terse facade-friendly method name.
     */
    public function engine(): WorkflowEngine
    {
        return $this->getEngine();
    }

    /**
     * Replace the workflow engine used by the package API.
     */
    public function setEngine(WorkflowEngine $engine): self
    {
        $this->engine = $engine;

        return $this;
    }

    /**
     * Replace the workflow engine using a fluent facade-friendly method name.
     */
    public function useEngine(WorkflowEngine $engine): self
    {
        return $this->setEngine($engine);
    }

    /**
     * Start the current active workflow version for the given subject model.
     *
     * @param  array<string, mixed>  $context
     */
    public function start(string $workflowCode, Model $subject, array $context = [], ?User $user = null): WorkflowInstance
    {
        return $this->startWithOptions($workflowCode, $subject, $context, $user);
    }

    /**
     * Start the current active workflow version with runtime options.
     *
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $options
     */
    public function startWithOptions(string $workflowCode, Model $subject, array $context = [], ?User $user = null, array $options = []): WorkflowInstance
    {
        $workflow = Workflow::where('code', $workflowCode)
            ->where('is_current_version', true)
            ->where('status', WorkflowStatus::Active)
            ->firstOrFail();

        return $this->engine->startWithOptions($workflow, $subject, $context, $user, $options);
    }

    /**
     * Perform an action on the current active step for the workflow instance.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws \Exception
     */
    public function performAction(WorkflowInstance $instance, string $actionCode, ?User $user = null, array $payload = []): void
    {
        $this->engine->performAction($instance, $actionCode, $user, $payload);
    }

    /**
     * Deliver a signal to a workflow instance.
     *
     * @param  array<string, mixed>  $payload
     */
    public function signal(WorkflowInstance $instance, string $signal, array $payload = [], ?User $user = null): void
    {
        $this->engine->signal($instance, $signal, $payload, $user);
    }

    /**
     * Execute a validated workflow update.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function update(WorkflowInstance $instance, string $update, array $payload = [], ?User $user = null): array
    {
        return $this->engine->update($instance, $update, $payload, $user);
    }

    /**
     * Read current workflow state or execute a configured query handler.
     *
     * @param  array<string, mixed>  $payload
     */
    public function query(WorkflowInstance $instance, string $query = 'state', array $payload = [], ?User $user = null): mixed
    {
        return $this->engine->query($instance, $query, $payload, $user);
    }

    /**
     * Cancel a running workflow instance.
     */
    public function cancel(WorkflowInstance $instance, ?string $reason = null, ?User $user = null): void
    {
        $this->engine->cancel($instance, $reason, $user);
    }

    /**
     * Terminate a running workflow instance.
     */
    public function terminate(WorkflowInstance $instance, ?string $reason = null, ?User $user = null): void
    {
        $this->engine->terminate($instance, $reason, $user);
    }

    /**
     * Retry the latest failed runtime step.
     */
    public function retry(WorkflowInstance $instance, ?User $user = null): void
    {
        $this->engine->retry($instance, $user);
    }

    /**
     * Continue a workflow as a fresh run using the same definition and subject.
     *
     * @param  array<string, mixed>  $context
     */
    public function continueAsNew(WorkflowInstance $instance, array $context = [], ?User $user = null): WorkflowInstance
    {
        return $this->engine->continueAsNew($instance, $context, $user);
    }

    /**
     * Start a child workflow for a parent runtime instance.
     *
     * @param  array<string, mixed>  $context
     */
    public function startChild(WorkflowInstance $parent, Workflow $workflow, Model $subject, array $context = [], ?User $user = null): WorkflowInstance
    {
        return $this->engine->startChild($parent, $workflow, $subject, $context, $user);
    }

    /**
     * Schedule a durable timer for a workflow instance.
     *
     * @param  array<string, mixed>  $payload
     */
    public function scheduleTimer(WorkflowInstance $instance, string $name, CarbonInterface $dueAt, array $payload = [], ?string $handler = null, ?User $user = null): WorkflowTimer
    {
        return $this->engine->scheduleTimer($instance, $name, $dueAt, $payload, $handler, $user);
    }

    /**
     * Fire pending timers due at or before the given time.
     *
     * @return Collection<int, WorkflowTimer>
     */
    public function fireDueTimers(?CarbonInterface $now = null): Collection
    {
        return $this->engine->fireDueTimers($now);
    }

    /**
     * Start pending workflow runs whose delayed start time has elapsed.
     *
     * @return Collection<int, WorkflowInstance>
     */
    public function processPendingStarts(?CarbonInterface $now = null): Collection
    {
        return $this->engine->processPendingStarts($now);
    }

    /**
     * Mark active or pending workflow runs timed out when their timeout expires.
     *
     * @return Collection<int, WorkflowInstance>
     */
    public function processTimeouts(?CarbonInterface $now = null): Collection
    {
        return $this->engine->processTimeouts($now);
    }

    /**
     * Merge workflow visibility search attributes.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function upsertSearchAttributes(WorkflowInstance $instance, array $attributes, ?User $user = null): void
    {
        $this->engine->upsertSearchAttributes($instance, $attributes, $user);
    }

    /**
     * Search workflow instances by visibility fields.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, WorkflowInstance>
     */
    public function search(array $filters = []): Collection
    {
        return $this->engine->search($filters);
    }

    /**
     * Schedule a Laravel-native workflow activity for worker execution.
     *
     * @param  class-string  $handler
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $options
     */
    public function scheduleActivity(WorkflowInstance $instance, string $name, string $handler, array $input = [], array $options = []): WorkflowActivity
    {
        return $this->activityService->schedule($instance, $name, $handler, $input, $options);
    }

    /**
     * Run due workflow activities for a worker queue.
     *
     * @return Collection<int, WorkflowActivity>
     */
    public function runDueActivities(?string $taskQueue = null, int $limit = 50, ?CarbonInterface $now = null): Collection
    {
        return $this->activityService->runDue($taskQueue, $limit, $now);
    }

    /**
     * Complete an activity that previously requested asynchronous completion.
     *
     * @param  array<string, mixed>  $result
     */
    public function completeAsyncActivity(string $token, array $result = []): WorkflowActivity
    {
        return $this->activityService->completeAsync($token, $result);
    }

    /**
     * Resolve the actions available to the given user on the instance.
     *
     * @return Collection<int, WorkflowStepAction>
     */
    public function getAvailableActions(WorkflowInstance $instance, ?User $user = null): Collection
    {
        return $this->actionResolver->resolve($instance, $user);
    }

    /**
     * Validate and activate a workflow definition.
     */
    public function activate(Workflow $workflow): bool
    {
        return $this->service->activate($workflow);
    }

    /**
     * Sync an attributed workflow definition class into persistent workflow models.
     *
     * @param  class-string|object  $workflowClass
     */
    public function syncAttributes(string|object $workflowClass, bool $activate = false): Workflow
    {
        return $this->attributeRegistrar->sync($workflowClass, $activate);
    }

    /**
     * Sync all attributed workflow classes configured in config/workflow.php.
     *
     * @return array<class-string, Workflow>
     */
    public function syncConfiguredAttributes(bool $activate = false): array
    {
        return $this->attributeRegistrar->syncConfigured($activate);
    }
}
