<?php

namespace HFlow\LaravelWorkflow\Services;

use Carbon\CarbonInterface;
use HFlow\LaravelWorkflow\Contracts\WorkflowQueryHandler;
use HFlow\LaravelWorkflow\Contracts\WorkflowSignalHandler;
use HFlow\LaravelWorkflow\Contracts\WorkflowTimerHandler;
use HFlow\LaravelWorkflow\Contracts\WorkflowUpdateHandler;
use HFlow\LaravelWorkflow\Contracts\WorkflowUpdateValidator;
use HFlow\LaravelWorkflow\Enums\ActionType;
use HFlow\LaravelWorkflow\Enums\ActorType;
use HFlow\LaravelWorkflow\Enums\HistoryEvent;
use HFlow\LaravelWorkflow\Enums\InstanceStatus;
use HFlow\LaravelWorkflow\Enums\StepStatus;
use HFlow\LaravelWorkflow\Enums\StepType;
use HFlow\LaravelWorkflow\Enums\TimerStatus;
use HFlow\LaravelWorkflow\Enums\TransitionType;
use HFlow\LaravelWorkflow\Events\WorkflowHistoryRecorded;
use HFlow\LaravelWorkflow\Models\Workflow;
use HFlow\LaravelWorkflow\Models\WorkflowHistory;
use HFlow\LaravelWorkflow\Models\WorkflowInstance;
use HFlow\LaravelWorkflow\Models\WorkflowStep;
use HFlow\LaravelWorkflow\Models\WorkflowStepAction;
use HFlow\LaravelWorkflow\Models\WorkflowStepInstance;
use HFlow\LaravelWorkflow\Models\WorkflowTimer;
use Illuminate\Contracts\Auth\Authenticatable as User;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WorkflowEngine
{
    /**
     * Create the engine with its resolver, condition evaluator, and container.
     */
    public function __construct(
        protected ?ActionResolver $actionResolver = null,
        protected ?ConditionEvaluator $conditionEvaluator = null,
        protected ?Container $container = null,
    ) {
        $this->container ??= \Illuminate\Container\Container::getInstance();
        $this->conditionEvaluator ??= new ConditionEvaluator($this->container);
        $this->actionResolver ??= new ActionResolver($this->conditionEvaluator, $this->container);
    }

    /**
     * Start a new workflow instance and enter the configured start step.
     *
     * @param  array<string, mixed>  $context
     */
    public function start(Workflow $workflow, Model $subject, array $context = [], ?User $user = null): WorkflowInstance
    {
        return $this->startWithOptions($workflow, $subject, $context, $user);
    }

    /**
     * Start a workflow instance with Temporal-style Laravel runtime options.
     *
     * @param  array<string, mixed>  $context
     * @param  array{
     *     workflow_identity?: string,
     *     run_id?: string,
     *     first_execution_run_id?: string,
     *     parent_instance_id?: int,
     *     memo?: array<string, mixed>,
     *     search_attributes?: array<string, mixed>,
     *     task_queue?: string,
     *     start_after?: CarbonInterface,
     *     start_delay_seconds?: int,
     *     execution_timeout_seconds?: int,
     *     run_timeout_seconds?: int
     * }  $options
     */
    public function startWithOptions(Workflow $workflow, Model $subject, array $context = [], ?User $user = null, array $options = []): WorkflowInstance
    {
        return DB::transaction(function () use ($workflow, $subject, $context, $user, $options) {
            $runId = $options['run_id'] ?? (string) Str::uuid();
            $startAfter = $options['start_after'] ?? null;

            if (! $startAfter instanceof CarbonInterface && isset($options['start_delay_seconds'])) {
                $startAfter = now()->addSeconds((int) $options['start_delay_seconds']);
            }

            $isDelayed = $startAfter instanceof CarbonInterface && $startAfter->isFuture();

            $instance = WorkflowInstance::create([
                'workflow_id' => $workflow->id,
                'workflow_version' => $workflow->version,
                'workflow_identity' => $options['workflow_identity'] ?? null,
                'run_id' => $runId,
                'first_execution_run_id' => $options['first_execution_run_id'] ?? $runId,
                'parent_instance_id' => $options['parent_instance_id'] ?? null,
                'subject_type' => get_class($subject),
                'subject_id' => $subject->getKey(),
                'status' => $isDelayed ? InstanceStatus::Pending : InstanceStatus::InProgress,
                'context' => $context,
                'memo' => $options['memo'] ?? null,
                'search_attributes' => $options['search_attributes'] ?? null,
                'task_queue' => $options['task_queue'] ?? null,
                'initiated_by' => $user?->getAuthIdentifier(),
                'started_at' => $isDelayed ? null : now(),
                'start_after' => $startAfter,
                'execution_timeout_at' => isset($options['execution_timeout_seconds'])
                    ? now()->addSeconds((int) $options['execution_timeout_seconds'])
                    : null,
                'run_timeout_at' => isset($options['run_timeout_seconds'])
                    ? now()->addSeconds((int) $options['run_timeout_seconds'])
                    : null,
            ]);

            $startStep = $workflow->startStep;

            if (! $startStep instanceof WorkflowStep) {
                throw new \Exception('The workflow does not have a valid start step.');
            }

            if ($isDelayed) {
                $this->logHistory($instance, null, null, $workflow->start_step_id, null, HistoryEvent::StartDelayed, $user, null, [
                    'start_after' => $startAfter->toJSON(),
                ]);

                return $instance;
            }

            $this->logHistory($instance, null, null, $workflow->start_step_id, null, HistoryEvent::Started, $user, null, [
                'run_id' => $runId,
                'workflow_identity' => $instance->workflow_identity,
            ]);

            $this->enterStep($instance, $startStep, $user);

            return $instance;
        });
    }

    /**
     * Perform an eligible action, close the active step, route, and audit the transition.
     *
     * @param  array<string, mixed>  $payload
     */
    public function performAction(WorkflowInstance $instance, string $actionCode, ?User $user = null, array $payload = []): void
    {
        $availableActions = $this->actionResolver->resolve($instance, $user);

        $action = $availableActions->where('code', $actionCode)->first();

        if (! $action) {
            throw new \Exception("Action '{$actionCode}' is not available for this user on this instance.");
        }

        // BR-AC-03 — requires_comment enforcement
        if ($action->requires_comment && empty($payload['comment'])) {
            throw new \Exception("Action '{$actionCode}' requires a comment.");
        }

        DB::transaction(function () use ($instance, $action, $user, $payload) {
            $currentStepInstance = $instance->stepInstances()
                ->where('step_id', $action->step_id)
                ->where('status', StepStatus::Active)
                ->first();

            if (! $currentStepInstance instanceof WorkflowStepInstance) {
                throw new \Exception("No active step instance found for action '{$action->code}'.");
            }

            // Run action handler side-effects (BR-AC-05)
            if ($action->handler && class_exists($action->handler)) {
                $handler = $this->container->make($action->handler);
                $handler->handle($currentStepInstance, $action->code, $payload);
            }

            // Close current step
            $currentStepInstance->update([
                'status' => $this->getTerminalStatusForAction($action),
                'completed_at' => now(),
                'acted_by' => $user?->getAuthIdentifier(),
                'action_taken' => $action->code,
                'comment' => $payload['comment'] ?? null,
            ]);

            $this->logHistory(
                $instance,
                $currentStepInstance->id,
                $currentStepInstance->step_id,
                null,
                $action->code,
                HistoryEvent::ActionPerformed,
                $user,
                $payload['comment'] ?? null
            );

            // Routing (BR-X-12)
            $nextStep = $this->resolveNextStep($instance, $action);

            if ($nextStep) {
                $this->enterStep($instance, $nextStep, $user);
            } else {
                // If no next step and not an end step, handle error or end
                if ($currentStepInstance->step->type !== StepType::End) {
                    $instance->update(['status' => InstanceStatus::Completed, 'completed_at' => now()]);
                    $this->logHistory($instance, null, $currentStepInstance->step_id, null, null, HistoryEvent::Completed, $user);
                }
            }
        });
    }

    /**
     * Deliver an external signal to a running workflow instance.
     *
     * @param  array<string, mixed>  $payload
     */
    public function signal(WorkflowInstance $instance, string $signal, array $payload = [], ?User $user = null): void
    {
        DB::transaction(function () use ($instance, $signal, $payload, $user) {
            $instance->refresh();
            $this->ensureInstanceCanReceiveEvents($instance);

            $context = $instance->context ?? [];
            $context['signals'] ??= [];
            $context['signals'][] = [
                'name' => $signal,
                'payload' => $payload,
                'received_at' => now()->toJSON(),
                'actor_id' => $user?->getAuthIdentifier(),
            ];

            $instance->update(['context' => $context]);

            $handlerClass = $this->runtimeHandlerClass($instance, 'signals', $signal);

            if ($handlerClass) {
                $handler = $this->container->make($handlerClass);

                if (! $handler instanceof WorkflowSignalHandler) {
                    throw new \Exception("Signal handler '{$handlerClass}' must implement WorkflowSignalHandler.");
                }

                $handler->handle($instance->fresh(), $signal, $payload, $user);
            }

            $this->logHistory($instance, null, $instance->current_step_id, null, $signal, HistoryEvent::SignalReceived, $user, null, [
                'payload' => $payload,
            ]);
        });
    }

    /**
     * Execute a validated workflow update and merge returned context changes.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function update(WorkflowInstance $instance, string $update, array $payload = [], ?User $user = null): array
    {
        $result = DB::transaction(function () use ($instance, $update, $payload, $user) {
            $instance->refresh();
            $this->ensureInstanceCanReceiveEvents($instance);

            $validatorClass = $this->runtimeHandlerClass($instance, 'update_validators', $update);

            if ($validatorClass) {
                $validator = $this->container->make($validatorClass);

                if (! $validator instanceof WorkflowUpdateValidator) {
                    throw new \Exception("Update validator '{$validatorClass}' must implement WorkflowUpdateValidator.");
                }

                if (! $validator->validate($instance, $update, $payload, $user)) {
                    $this->logHistory($instance, null, $instance->current_step_id, null, $update, HistoryEvent::UpdateRejected, $user, null, [
                        'payload' => $payload,
                    ]);

                    return [
                        'rejected' => true,
                        'changes' => [],
                    ];
                }
            }

            $handlerClass = $this->runtimeHandlerClass($instance, 'updates', $update);
            $changes = [];

            if ($handlerClass) {
                $handler = $this->container->make($handlerClass);

                if (! $handler instanceof WorkflowUpdateHandler) {
                    throw new \Exception("Update handler '{$handlerClass}' must implement WorkflowUpdateHandler.");
                }

                $changes = $handler->handle($instance, $update, $payload, $user);
            }

            if ($changes !== []) {
                $instance->update(['context' => array_replace_recursive($instance->context ?? [], $changes)]);
            }

            $this->logHistory($instance, null, $instance->current_step_id, null, $update, HistoryEvent::UpdateAccepted, $user, null, [
                'payload' => $payload,
                'changes' => $changes,
            ]);

            return [
                'rejected' => false,
                'changes' => $changes,
            ];
        });

        if ($result['rejected']) {
            throw new \Exception("Update '{$update}' was rejected by its validator.");
        }

        return $result['changes'];
    }

    /**
     * Execute a read-only query against the workflow state or a configured query handler.
     *
     * @param  array<string, mixed>  $payload
     */
    public function query(WorkflowInstance $instance, string $query = 'state', array $payload = [], ?User $user = null): mixed
    {
        $instance->refresh();
        $handlerClass = $this->runtimeHandlerClass($instance, 'queries', $query);

        if ($handlerClass) {
            $handler = $this->container->make($handlerClass);

            if (! $handler instanceof WorkflowQueryHandler) {
                throw new \Exception("Query handler '{$handlerClass}' must implement WorkflowQueryHandler.");
            }

            return $handler->handle($instance, $query, $payload, $user);
        }

        return [
            'id' => $instance->id,
            'workflow_id' => $instance->workflow_id,
            'workflow_version' => $instance->workflow_version,
            'status' => $instance->status->value,
            'current_step_id' => $instance->current_step_id,
            'context' => $instance->context ?? [],
        ];
    }

    /**
     * Cancel a running workflow instance and all active steps.
     */
    public function cancel(WorkflowInstance $instance, ?string $reason = null, ?User $user = null): void
    {
        DB::transaction(function () use ($instance, $reason, $user) {
            $instance->refresh();

            if (in_array($instance->status, [InstanceStatus::Completed, InstanceStatus::Cancelled], true)) {
                return;
            }

            $instance->stepInstances()
                ->where('status', StepStatus::Active)
                ->update([
                    'status' => StepStatus::Cancelled,
                    'completed_at' => now(),
                    'comment' => $reason,
                ]);

            $instance->update([
                'status' => InstanceStatus::Cancelled,
                'completed_at' => now(),
            ]);

            $this->logHistory($instance, null, $instance->current_step_id, null, null, HistoryEvent::Cancelled, $user, $reason);
        });
    }

    /**
     * Terminate a workflow instance without cooperative cancellation.
     */
    public function terminate(WorkflowInstance $instance, ?string $reason = null, ?User $user = null): void
    {
        DB::transaction(function () use ($instance, $reason, $user) {
            $instance->refresh();

            if ($this->isClosed($instance)) {
                return;
            }

            $instance->stepInstances()
                ->where('status', StepStatus::Active)
                ->update([
                    'status' => StepStatus::Cancelled,
                    'completed_at' => now(),
                    'comment' => $reason,
                ]);

            $instance->timers()
                ->where('status', TimerStatus::Pending)
                ->update(['status' => TimerStatus::Cancelled]);

            $instance->update([
                'status' => InstanceStatus::Terminated,
                'completed_at' => now(),
            ]);

            $this->logHistory($instance, null, $instance->current_step_id, null, null, HistoryEvent::Terminated, $user, $reason);
        });
    }

    /**
     * Retry the latest failed step instance, usually an automated step.
     */
    public function retry(WorkflowInstance $instance, ?User $user = null): void
    {
        DB::transaction(function () use ($instance, $user) {
            $instance->refresh();

            $failedStepInstance = $instance->stepInstances()
                ->where('status', StepStatus::Failed)
                ->latest('id')
                ->first();

            if (! $failedStepInstance instanceof WorkflowStepInstance) {
                throw new \Exception('No failed step instance is available to retry.');
            }

            $instance->update(['status' => InstanceStatus::InProgress]);

            $this->logHistory($instance, $failedStepInstance->id, $failedStepInstance->step_id, null, null, HistoryEvent::Retried, $user);
            $this->enterStep($instance, $failedStepInstance->step, $user);
        });
    }

    /**
     * Close the current run and start a fresh run on the same workflow version and subject.
     *
     * @param  array<string, mixed>  $context
     */
    public function continueAsNew(WorkflowInstance $instance, array $context = [], ?User $user = null): WorkflowInstance
    {
        return DB::transaction(function () use ($instance, $context, $user) {
            $instance->refresh();

            $this->logHistory($instance, null, $instance->current_step_id, null, null, HistoryEvent::ContinuedAsNew, $user, null, [
                'context' => $context,
            ]);

            $instance->update([
                'status' => InstanceStatus::Completed,
                'completed_at' => now(),
            ]);

            return $this->startWithOptions($instance->workflow, $instance->subject, $context, $user, [
                'workflow_identity' => $instance->workflow_identity,
                'first_execution_run_id' => $instance->first_execution_run_id ?? $instance->run_id,
                'memo' => $instance->memo ?? [],
                'search_attributes' => $instance->search_attributes ?? [],
                'task_queue' => $instance->task_queue,
            ]);
        });
    }

    /**
     * Start a child workflow and record the parent-child relationship in history/context.
     *
     * @param  array<string, mixed>  $context
     */
    public function startChild(WorkflowInstance $parent, Workflow $workflow, Model $subject, array $context = [], ?User $user = null): WorkflowInstance
    {
        return DB::transaction(function () use ($parent, $workflow, $subject, $context, $user) {
            $parent->refresh();

            $child = $this->startWithOptions($workflow, $subject, array_replace_recursive($context, [
                'parent_workflow_instance_id' => $parent->id,
            ]), $user, [
                'parent_instance_id' => $parent->id,
            ]);

            $this->logHistory($parent, null, $parent->current_step_id, null, null, HistoryEvent::ChildStarted, $user, null, [
                'child_workflow_instance_id' => $child->id,
                'child_workflow_id' => $workflow->id,
            ]);

            return $child;
        });
    }

    /**
     * Persist a durable timer for later processing by a scheduler command.
     *
     * @param  array<string, mixed>  $payload
     */
    public function scheduleTimer(WorkflowInstance $instance, string $name, CarbonInterface $dueAt, array $payload = [], ?string $handler = null, ?User $user = null): WorkflowTimer
    {
        return DB::transaction(function () use ($instance, $name, $dueAt, $payload, $handler, $user) {
            $instance->refresh();
            $this->ensureInstanceCanReceiveEvents($instance);

            $timer = WorkflowTimer::create([
                'tenant_id' => $instance->tenant_id,
                'workflow_instance_id' => $instance->id,
                'name' => $name,
                'status' => TimerStatus::Pending,
                'payload' => $payload,
                'handler' => $handler ?? $this->runtimeHandlerClass($instance, 'timers', $name),
                'due_at' => $dueAt,
            ]);

            $this->logHistory($instance, null, $instance->current_step_id, null, $name, HistoryEvent::TimerScheduled, $user, null, [
                'timer_id' => $timer->id,
                'due_at' => $dueAt->toJSON(),
                'payload' => $payload,
            ]);

            return $timer;
        });
    }

    /**
     * Fire all due timers and return the timers that were processed.
     *
     * @return Collection<int, WorkflowTimer>
     */
    public function fireDueTimers(?CarbonInterface $now = null): Collection
    {
        $now ??= now();
        $processed = collect();

        WorkflowTimer::query()
            ->where('status', TimerStatus::Pending)
            ->where('due_at', '<=', $now)
            ->orderBy('due_at')
            ->each(function (WorkflowTimer $timer) use ($processed) {
                DB::transaction(function () use ($timer, $processed) {
                    $timer->refresh();

                    if ($timer->status !== TimerStatus::Pending) {
                        return;
                    }

                    $instance = $timer->workflowInstance;

                    try {
                        if ($timer->handler) {
                            $handler = $this->container->make($timer->handler);

                            if (! $handler instanceof WorkflowTimerHandler) {
                                throw new \Exception("Timer handler '{$timer->handler}' must implement WorkflowTimerHandler.");
                            }

                            $handler->handle($instance, $timer);
                        }

                        $timer->update([
                            'status' => TimerStatus::Fired,
                            'fired_at' => now(),
                        ]);

                        $this->logHistory($instance, null, $instance->current_step_id, null, $timer->name, HistoryEvent::TimerFired, null, null, [
                            'timer_id' => $timer->id,
                            'payload' => $timer->payload ?? [],
                        ]);

                        $processed->push($timer->fresh());
                    } catch (\Exception $e) {
                        $timer->update(['status' => TimerStatus::Failed]);
                        $this->logHistory($instance, null, $instance->current_step_id, null, $timer->name, HistoryEvent::Error, null, $e->getMessage(), [
                            'timer_id' => $timer->id,
                        ]);

                        throw $e;
                    }
                });
            });

        return $processed;
    }

    /**
     * Start all pending workflow runs whose start delay has elapsed.
     *
     * @return Collection<int, WorkflowInstance>
     */
    public function processPendingStarts(?CarbonInterface $now = null): Collection
    {
        $now ??= now();
        $started = collect();

        WorkflowInstance::query()
            ->where('status', InstanceStatus::Pending)
            ->whereNotNull('start_after')
            ->where('start_after', '<=', $now)
            ->orderBy('start_after')
            ->each(function (WorkflowInstance $instance) use ($started) {
                DB::transaction(function () use ($instance, $started) {
                    $instance->refresh();

                    if ($instance->status !== InstanceStatus::Pending) {
                        return;
                    }

                    $startStep = $instance->workflow->startStep;

                    if (! $startStep instanceof WorkflowStep) {
                        throw new \Exception('The workflow does not have a valid start step.');
                    }

                    $instance->update([
                        'status' => InstanceStatus::InProgress,
                        'started_at' => now(),
                    ]);

                    $this->logHistory($instance, null, null, $instance->workflow->start_step_id, null, HistoryEvent::Started, null, null, [
                        'run_id' => $instance->run_id,
                        'workflow_identity' => $instance->workflow_identity,
                        'delayed' => true,
                    ]);

                    $this->enterStep($instance, $startStep);
                    $started->push($instance->fresh());
                });
            });

        return $started;
    }

    /**
     * Mark active or pending workflow runs timed out when their timeout expires.
     *
     * @return Collection<int, WorkflowInstance>
     */
    public function processTimeouts(?CarbonInterface $now = null): Collection
    {
        $now ??= now();
        $timedOut = collect();

        WorkflowInstance::query()
            ->whereIn('status', [InstanceStatus::Pending, InstanceStatus::InProgress, InstanceStatus::OnHold])
            ->where(function ($query) use ($now) {
                $query
                    ->whereNotNull('execution_timeout_at')
                    ->where('execution_timeout_at', '<=', $now)
                    ->orWhere(function ($query) use ($now) {
                        $query
                            ->whereNotNull('run_timeout_at')
                            ->where('run_timeout_at', '<=', $now);
                    });
            })
            ->each(function (WorkflowInstance $instance) use ($timedOut) {
                DB::transaction(function () use ($instance, $timedOut) {
                    $instance->refresh();

                    if ($this->isClosed($instance)) {
                        return;
                    }

                    $instance->stepInstances()
                        ->where('status', StepStatus::Active)
                        ->update([
                            'status' => StepStatus::Failed,
                            'completed_at' => now(),
                            'comment' => 'Workflow timed out.',
                        ]);

                    $instance->timers()
                        ->where('status', TimerStatus::Pending)
                        ->update(['status' => TimerStatus::Cancelled]);

                    $instance->update([
                        'status' => InstanceStatus::TimedOut,
                        'completed_at' => now(),
                    ]);

                    $this->logHistory($instance, null, $instance->current_step_id, null, null, HistoryEvent::TimedOut, null, 'Workflow timed out.', [
                        'execution_timeout_at' => $instance->execution_timeout_at?->toJSON(),
                        'run_timeout_at' => $instance->run_timeout_at?->toJSON(),
                    ]);

                    $timedOut->push($instance->fresh());
                });
            });

        return $timedOut;
    }

    /**
     * Merge search attributes used for application-level workflow visibility.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function upsertSearchAttributes(WorkflowInstance $instance, array $attributes, ?User $user = null): void
    {
        DB::transaction(function () use ($instance, $attributes, $user) {
            $instance->refresh();

            $instance->update([
                'search_attributes' => array_replace($instance->search_attributes ?? [], $attributes),
            ]);

            $this->logHistory($instance, null, $instance->current_step_id, null, null, HistoryEvent::SearchAttributesUpdated, $user, null, [
                'attributes' => $attributes,
            ]);
        });
    }

    /**
     * Query workflow instances by common visibility fields and search attributes.
     *
     * @param  array{
     *     status?: string|InstanceStatus,
     *     workflow_code?: string,
     *     workflow_identity?: string,
     *     run_id?: string,
     *     task_queue?: string,
     *     search_attributes?: array<string, mixed>
     * }  $filters
     * @return Collection<int, WorkflowInstance>
     */
    public function search(array $filters = []): Collection
    {
        return WorkflowInstance::query()
            ->when($filters['status'] ?? null, function ($query, string|InstanceStatus $status) {
                $query->where('status', $status instanceof InstanceStatus ? $status->value : $status);
            })
            ->when($filters['workflow_code'] ?? null, function ($query, string $code) {
                $query->whereHas('workflow', fn ($query) => $query->where('code', $code));
            })
            ->when($filters['workflow_identity'] ?? null, fn ($query, string $identity) => $query->where('workflow_identity', $identity))
            ->when($filters['run_id'] ?? null, fn ($query, string $runId) => $query->where('run_id', $runId))
            ->when($filters['task_queue'] ?? null, fn ($query, string $taskQueue) => $query->where('task_queue', $taskQueue))
            ->when($filters['search_attributes'] ?? null, function ($query, array $attributes) {
                foreach ($attributes as $key => $value) {
                    $query->where("search_attributes->{$key}", $value);
                }
            })
            ->latest('id')
            ->get();
    }

    /**
     * Create an active step instance and run any immediate step side effects.
     */
    protected function enterStep(WorkflowInstance $instance, WorkflowStep $step, ?User $user = null): void
    {
        $stepInstance = WorkflowStepInstance::create([
            'workflow_instance_id' => $instance->id,
            'step_id' => $step->id,
            'status' => StepStatus::Active,
            'entered_at' => now(),
            'due_at' => $step->sla_seconds ? now()->addSeconds($step->sla_seconds) : null,
        ]);

        $instance->update(['current_step_id' => $step->id]);

        $this->logHistory($instance, $stepInstance->id, null, $step->id, null, HistoryEvent::StepEntered, $user);

        // Automated steps handling (BR-X-21)
        if ($step->type === StepType::Automated) {
            $this->handleAutomatedStep($instance, $stepInstance, $user);
        }

        // End step handling
        if ($step->type === StepType::End) {
            $instance->update(['status' => InstanceStatus::Completed, 'completed_at' => now()]);
            $stepInstance->update(['status' => StepStatus::Completed, 'completed_at' => now()]);
            $this->logHistory($instance, $stepInstance->id, $step->id, null, null, HistoryEvent::Completed, $user);
        }
    }

    /**
     * Execute the automated step handler and route to the next automatic or fallback step.
     */
    protected function handleAutomatedStep(WorkflowInstance $instance, WorkflowStepInstance $stepInstance, ?User $user = null): void
    {
        try {
            $step = $stepInstance->step;
            $data = [];

            if ($step->handler && class_exists($step->handler)) {
                $handler = $this->container->make($step->handler);
                $data = $handler->handle($stepInstance, $instance->context);
            }

            $stepInstance->update([
                'status' => StepStatus::Completed,
                'completed_at' => now(),
                'data' => $data,
                'action_taken' => 'system_complete',
            ]);

            // Resolve next step via automatic transitions
            $nextStep = $this->resolveAutomaticTransition($instance, $step);

            if ($nextStep) {
                $this->enterStep($instance, $nextStep, $user);
            } else {
                // Sequential fallback (BR-R-05)
                $nextStep = $this->getSequentialFallback($instance, $step);
                if ($nextStep) {
                    $this->enterStep($instance, $nextStep, $user);
                }
            }
        } catch (\Exception $e) {
            $stepInstance->update(['status' => StepStatus::Failed]);
            $instance->update(['status' => InstanceStatus::Failed]);
            $this->logHistory($instance, $stepInstance->id, null, null, null, HistoryEvent::Error, null, $e->getMessage());
        }
    }

    /**
     * Resolve the next step from the action target, matching transitions, or sequential fallback.
     */
    protected function resolveNextStep(WorkflowInstance $instance, WorkflowStepAction $action): ?WorkflowStep
    {
        // 1. Explicit target on action
        if ($action->target_step_id) {
            return WorkflowStep::find($action->target_step_id);
        }

        // 2. Matching transitions
        $transition = $instance->workflow->transitions()
            ->where('from_step_id', $action->step_id)
            ->where('action_id', $action->id)
            ->orderByDesc('priority')
            ->get()
            ->filter(fn ($t) => $this->conditionEvaluator->evaluate($t->condition, $instance, $instance->subject, $instance->context))
            ->first();

        if ($transition) {
            return WorkflowStep::find($transition->to_step_id);
        }

        // 3. Sequential fallback
        return $this->getSequentialFallback($instance, $action->step);
    }

    /**
     * Resolve the highest-priority automatic or conditional transition for an automated step.
     */
    protected function resolveAutomaticTransition(WorkflowInstance $instance, WorkflowStep $step): ?WorkflowStep
    {
        $transition = $instance->workflow->transitions()
            ->where('from_step_id', $step->id)
            ->whereIn('type', [TransitionType::Automatic, TransitionType::Conditional])
            ->orderByDesc('priority')
            ->get()
            ->filter(fn ($t) => $this->conditionEvaluator->evaluate($t->condition, $instance, $instance->subject, $instance->context))
            ->first();

        return $transition ? WorkflowStep::find($transition->to_step_id) : null;
    }

    /**
     * Get the next step by position when explicit transitions are not required.
     */
    protected function getSequentialFallback(WorkflowInstance $instance, WorkflowStep $currentStep): ?WorkflowStep
    {
        if ($instance->workflow->require_explicit_transitions) {
            return null;
        }

        return $instance->workflow->steps()
            ->where('position', '>', $currentStep->position)
            ->orderBy('position')
            ->first();
    }

    /**
     * Map an action type to the status used when closing the current step instance.
     */
    protected function getTerminalStatusForAction(WorkflowStepAction $action): StepStatus
    {
        return match ($action->type) {
            ActionType::Reject => StepStatus::Rejected,
            ActionType::Skip => StepStatus::Skipped,
            ActionType::Return => StepStatus::Returned,
            default => StepStatus::Completed,
        };
    }

    /**
     * Resolve handler class names from workflow config sections.
     */
    protected function runtimeHandlerClass(WorkflowInstance $instance, string $section, string $name): ?string
    {
        $config = $instance->workflow->config ?? [];
        $handler = $config[$section][$name] ?? null;

        if (is_array($handler)) {
            $handler = $handler['handler'] ?? null;
        }

        return is_string($handler) && class_exists($handler) ? $handler : null;
    }

    /**
     * Guard external runtime events from mutating finished instances.
     */
    protected function ensureInstanceCanReceiveEvents(WorkflowInstance $instance): void
    {
        if ($this->isClosed($instance)) {
            throw new \Exception('Completed or cancelled workflow instances cannot receive runtime events.');
        }
    }

    /**
     * Determine whether the workflow run is in a terminal state.
     */
    protected function isClosed(WorkflowInstance $instance): bool
    {
        return in_array($instance->status, [
            InstanceStatus::Completed,
            InstanceStatus::Cancelled,
            InstanceStatus::Terminated,
            InstanceStatus::TimedOut,
            InstanceStatus::Rejected,
        ], true);
    }

    /**
     * Persist an immutable history event for workflow auditability.
     *
     * @param  array<string, mixed>  $metadata
     */
    protected function logHistory(
        WorkflowInstance $instance,
        ?int $stepInstanceId,
        ?int $fromStepId,
        ?int $toStepId,
        ?string $actionCode,
        HistoryEvent $event,
        ?User $user = null,
        ?string $comment = null,
        array $metadata = []
    ): void {
        $history = WorkflowHistory::create([
            'workflow_instance_id' => $instance->id,
            'step_instance_id' => $stepInstanceId,
            'from_step_id' => $fromStepId,
            'to_step_id' => $toStepId,
            'action_code' => $actionCode,
            'event' => $event,
            'actor_id' => $user?->getAuthIdentifier(),
            'actor_type' => $user ? ActorType::User : ActorType::System,
            'comment' => $comment,
            'metadata' => $metadata,
            'performed_at' => now(),
        ]);

        event(new WorkflowHistoryRecorded($history));
    }
}
