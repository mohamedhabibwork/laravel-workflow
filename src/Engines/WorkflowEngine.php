<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Engines;

use HFlow\LaravelWorkflow\Actions\ActionSet;
use HFlow\LaravelWorkflow\Contracts\WorkflowEngine as WorkflowEngineContract;
use HFlow\LaravelWorkflow\Engines\Authorizers\AuthorizerRegistry;
use HFlow\LaravelWorkflow\Engines\Authorizers\CustomAuthorizerDispatcher;
use HFlow\LaravelWorkflow\Engines\Authorizers\PermissionsAuthorizer;
use HFlow\LaravelWorkflow\Engines\Authorizers\PublicAuthorizer;
use HFlow\LaravelWorkflow\Engines\Authorizers\RolesAuthorizer;
use HFlow\LaravelWorkflow\Engines\Authorizers\UsersAuthorizer;
use HFlow\LaravelWorkflow\Engines\Conditions\ConditionEvaluator;
use HFlow\LaravelWorkflow\Engines\Conditions\ExpressionConditionEvaluator;
use HFlow\LaravelWorkflow\Enums\ActionAvailabilityMode;
use HFlow\LaravelWorkflow\Enums\ActionType;
use HFlow\LaravelWorkflow\Enums\ActorType;
use HFlow\LaravelWorkflow\Enums\AssignmentStatus;
use HFlow\LaravelWorkflow\Enums\AuthorizationMode;
use HFlow\LaravelWorkflow\Enums\HistoryEvent;
use HFlow\LaravelWorkflow\Enums\InstanceStatus;
use HFlow\LaravelWorkflow\Enums\MatchMode;
use HFlow\LaravelWorkflow\Enums\StepInstanceStatus;
use HFlow\LaravelWorkflow\Enums\StepType;
use HFlow\LaravelWorkflow\Enums\TransitionType;
use HFlow\LaravelWorkflow\Enums\WorkflowStatus;
use HFlow\LaravelWorkflow\Enums\WorkflowType;
use HFlow\LaravelWorkflow\Exceptions\ActionNotAvailableException;
use HFlow\LaravelWorkflow\Exceptions\CommentRequiredException;
use HFlow\LaravelWorkflow\Exceptions\InvalidStateException;
use HFlow\LaravelWorkflow\Exceptions\InvalidWorkflowException;
use HFlow\LaravelWorkflow\Exceptions\NotEligibleException;
use HFlow\LaravelWorkflow\Exceptions\ReturnNotAllowedException;
use HFlow\LaravelWorkflow\Exceptions\SkipNotAllowedException;
use HFlow\LaravelWorkflow\Exceptions\TransitionNotFoundException;
use HFlow\LaravelWorkflow\Exceptions\WorkflowSubjectMismatchException;
use HFlow\LaravelWorkflow\Exceptions\WorkflowTerminalException;
use HFlow\LaravelWorkflow\Models\Workflow;
use HFlow\LaravelWorkflow\Models\WorkflowAssignment;
use HFlow\LaravelWorkflow\Models\WorkflowCondition;
use HFlow\LaravelWorkflow\Models\WorkflowInstance;
use HFlow\LaravelWorkflow\Models\WorkflowStep;
use HFlow\LaravelWorkflow\Models\WorkflowStepAction;
use HFlow\LaravelWorkflow\Models\WorkflowStepAssignee;
use HFlow\LaravelWorkflow\Models\WorkflowStepInstance;
use HFlow\LaravelWorkflow\Models\WorkflowTransition;
use HFlow\LaravelWorkflow\Observability\HistoryRecorder;
use HFlow\LaravelWorkflow\StateMachine\WorkflowStateMachine;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Default implementation of {@see WorkflowEngine}.
 *
 * Phase 3 (US1) implements the design-time surface:
 *   - {@see self::define()}       Create a draft workflow from a structured array
 *   - {@see self::createNewVersion()} Clone a workflow into a new draft version
 *   - {@see self::activate()}     Validate + flip a draft to the active version
 *   - {@see self::versions()}     List all versions of a workflow
 *
 * Phase 4 (US2) implements:
 *   - {@see self::start()}        Start an instance, pin version, append history
 *   - {@see self::currentStep()}  Return the active step instance(s)
 *
 * Phase 5 (US3) implements:
 *   - {@see self::availableActions()}  Delegate to AvailableActionsResolver
 *   - {@see self::perform()}           Full orchestrator (eligibility, availability,
 *                                      handler invocation, quorum, transition,
 *                                      step enter/exit, history append).
 *
 * Phase 6+ (US4-US7) stub for: skipStep, returnToStep, hold, resume, cancel, history.
 *
 * @phpstan-type StepDefinition array{
 *     key: string,
 *     name: string,
 *     type: string,
 *     position?: int,
 *     authorization_mode?: string,
 *     match_mode?: string,
 *     custom_authorizer?: string|null,
 *     handler?: string|null,
 *     is_skippable?: bool,
 *     is_returnable?: bool,
 *     sla_seconds?: int|null,
 *     config?: array<string, mixed>|null,
 *     assignees?: list<array<string, mixed>>,
 *     actions?: list<array<string, mixed>>,
 * }
 * @phpstan-type TransitionDefinition array{
 *     from: string,
 *     to: string,
 *     action?: string|null,
 *     priority?: int,
 *     condition?: string|null,
 *     description?: string|null,
 * }
 * @phpstan-type ConditionDefinition array{
 *     name: string,
 *     kind: string,
 *     expression: array<string, mixed>,
 *     description?: string|null,
 * }
 */
final class WorkflowEngine implements WorkflowEngineContract
{
    /**
     * @param  HistoryRecorder|null  $historyRecorder  Optional: when present,
     *                                                 {@see self::start()} (and later US3-US7 methods) will append
     *                                                 history rows. When null, a default recorder backed by the
     *                                                 Laravel `events` container binding is used.
     * @param  AvailableActionsResolver|null  $actionsResolver  Optional: when present, used by {@see self::availableActions()}
     *                                                          and {@see self::perform()}. When null, a default is resolved
     *                                                          on demand.
     * @param  TransitionResolver|null  $transitionResolver  Optional: when present, used by {@see self::perform()}.
     * @param  QuorumEvaluator|null  $quorumEvaluator  Optional: when present, used by {@see self::perform()}.
     * @param  AssignmentMaterializer|null  $assignmentMaterializer  Optional: when present, used by {@see self::perform()}.
     * @param  HandlerInvoker|null  $handlerInvoker  Optional: when present, used by {@see self::perform()}.
     * @param  ConditionEvaluator|null  $conditionEvaluator  Optional: when present, used by skip/return guards.
     * @param  AutomationRunner|null  $automationRunner  Optional: when present, used by US5 (start/perform/retry on
     *                                                   automated steps).
     */
    public function __construct(
        private readonly ?HistoryRecorder $historyRecorder = null,
        private readonly ?AvailableActionsResolver $actionsResolver = null,
        private readonly ?TransitionResolver $transitionResolver = null,
        private readonly ?QuorumEvaluator $quorumEvaluator = null,
        private readonly ?AssignmentMaterializer $assignmentMaterializer = null,
        private readonly ?HandlerInvoker $handlerInvoker = null,
        private readonly ?ConditionEvaluator $conditionEvaluator = null,
        private readonly ?AutomationRunner $automationRunner = null,
    ) {}

    /**
     * Resolve the {@see HistoryRecorder} for this engine, creating a default
     * one on demand if the host did not inject one.
     */
    private function recorder(): HistoryRecorder
    {
        if ($this->historyRecorder instanceof HistoryRecorder) {
            return $this->historyRecorder;
        }

        return new HistoryRecorder(app(Dispatcher::class));
    }

    private function actionsResolver(): AvailableActionsResolver
    {
        if ($this->actionsResolver instanceof AvailableActionsResolver) {
            return $this->actionsResolver;
        }

        $registry = app()->bound(AuthorizerRegistry::class)
            ? app()->make(AuthorizerRegistry::class)
            : (function (): AuthorizerRegistry {
                $r = new AuthorizerRegistry;
                $r->register(new PublicAuthorizer);
                $r->register(new RolesAuthorizer);
                $r->register(new PermissionsAuthorizer);
                $r->register(new UsersAuthorizer);
                $r->register(new CustomAuthorizerDispatcher);

                return $r;
            })();

        $condition = app()->bound(ConditionEvaluator::class)
            ? app()->make(ConditionEvaluator::class)
            : new ConditionEvaluator(new ExpressionConditionEvaluator);

        return new AvailableActionsResolver($registry, $condition);
    }

    private function transitionResolver(): TransitionResolver
    {
        return $this->transitionResolver ?? new TransitionResolver;
    }

    private function quorumEvaluator(): QuorumEvaluator
    {
        return $this->quorumEvaluator ?? new QuorumEvaluator;
    }

    private function assignmentMaterializer(): AssignmentMaterializer
    {
        return $this->assignmentMaterializer ?? new AssignmentMaterializer;
    }

    private function handlerInvoker(): HandlerInvoker
    {
        return $this->handlerInvoker ?? new HandlerInvoker;
    }

    private function conditionEvaluator(): ConditionEvaluator
    {
        if ($this->conditionEvaluator instanceof ConditionEvaluator) {
            return $this->conditionEvaluator;
        }

        return new ConditionEvaluator(new ExpressionConditionEvaluator);
    }

    private function automationRunner(): AutomationRunner
    {
        if ($this->automationRunner instanceof AutomationRunner) {
            return $this->automationRunner;
        }

        return new AutomationRunner(
            $this->recorder(),
            $this->handlerInvoker(),
            $this->transitionResolver(),
        );
    }

    /**
     * Build the runtime context used to evaluate guard conditions
     * (subject.*, context.*, user.*, instance.*).
     *
     * @return array<string, mixed>
     */
    private function buildConditionContext(
        WorkflowInstance $instance,
        WorkflowStep $step,
        mixed $user,
    ): array {
        $context = (array) ($instance->context ?? []);

        return [
            'subject' => $instance->subject,
            'context' => $context,
            'user' => $user,
            'instance' => [
                'id' => $instance->getKey(),
                'uuid' => $instance->uuid,
                'status' => $instance->status?->value,
                'workflow_id' => $instance->workflow_id,
                'workflow_version' => (int) $instance->workflow_version,
                'current_step_id' => $instance->current_step_id,
                'current_step_code' => $step->code,
            ],
        ];
    }

    // -------------------------------------------------------------------
    //  US1 — Design-time: define / activate / version
    // -------------------------------------------------------------------

    /**
     * Define a new draft workflow from a structured array.
     *
     * Expected `$definition` shape:
     *   - name:        string (required)
     *   - description: ?string
     *   - type:        'approval'|'automation'|'generic' (default 'generic')
     *   - subject_type: ?string
     *   - require_explicit_transitions: bool
     *   - config: ?array
     *   - steps: list<StepDefinition>
     *   - transitions: list<TransitionDefinition>
     *   - conditions: list<ConditionDefinition>
     *
     * The created workflow is `status = draft`, `is_current_version = false`.
     * It can be activated later via {@see self::activate()}.
     *
     * @param  array<string, mixed>  $definition
     *
     * @throws InvalidWorkflowException
     */
    public function define(string $key, array $definition): Workflow
    {
        return DB::transaction(function () use ($key, $definition): Workflow {
            $workflow = new Workflow;
            $workflow->fill([
                'name' => (string) ($definition['name'] ?? $key),
                'code' => $key,
                'description' => $definition['description'] ?? null,
                'type' => $this->coerceEnum(WorkflowType::class, $definition['type'] ?? null, WorkflowType::Generic),
                'subject_type' => $definition['subject_type'] ?? null,
                'version' => 1,
                'is_current_version' => false,
                'status' => WorkflowStatus::Draft,
                'require_explicit_transitions' => (bool) ($definition['require_explicit_transitions'] ?? false),
                'config' => $definition['config'] ?? null,
            ]);
            $workflow->save();

            $stepIds = $this->createSteps($workflow, $definition['steps'] ?? []);

            // Resolve the start step's `key` so the `__start__` marker can be
            // resolved to its actual `from_step_id` (a transition whose
            // `from = '__start__'` is a convenience for "from the workflow's
            // start step"; the resolver needs a concrete step id to match).
            $startStepKey = null;
            foreach (($definition['steps'] ?? []) as $row) {
                if (is_array($row) && ($row['type'] ?? null) === StepType::Start->value && isset($row['key'])) {
                    $startStepKey = (string) $row['key'];
                    break;
                }
            }

            // Wire transitions between steps
            foreach (($definition['transitions'] ?? []) as $i => $row) {
                $this->assertTransitionRow($row, $i, $stepIds);
                $fromKey = (string) $row['from'];
                $fromStepId = $fromKey === '__start__'
                    ? ($startStepKey !== null ? $stepIds[$startStepKey] ?? null : null)
                    : $stepIds[$fromKey];
                WorkflowTransition::query()->create([
                    'workflow_id' => $workflow->getKey(),
                    'from_step_id' => $fromStepId,
                    'to_step_id' => $stepIds[$row['to']],
                    'type' => $this->coerceEnum(
                        TransitionType::class,
                        $row['type'] ?? null,
                        TransitionType::Forward,
                    ),
                    'priority' => (int) ($row['priority'] ?? 0),
                    'condition' => $row['condition'] ?? null,
                    'description' => $row['description'] ?? null,
                ]);
            }

            // Wire workflow-level conditions
            foreach (($definition['conditions'] ?? []) as $i => $row) {
                $this->assertConditionRow($row, $i);
                WorkflowCondition::query()->create([
                    'workflow_id' => $workflow->getKey(),
                    'name' => (string) $row['name'],
                    'code' => (string) ($row['code'] ?? $row['name']),
                    'kind' => (string) $row['kind'],
                    'expression' => $row['expression'],
                    'description' => $row['description'] ?? null,
                ]);
            }

            return $workflow->refresh();
        });
    }

    /**
     * Create a new draft version of an existing workflow.
     *
     * Deep-clones steps (with their assignees and actions), transitions, and
     * workflow-level conditions. Bumps `version`, sets `status = draft`,
     * `is_current_version = false`. Does NOT touch any `WorkflowInstance`.
     *
     * @param  array<string, mixed>  $overrides  Applied to the cloned workflow
     *                                           (e.g. `['name' => 'Q2 update']`)
     *
     * @throws Throwable
     */
    public function createNewVersion(Workflow $workflow, array $overrides = []): Workflow
    {
        return DB::transaction(function () use ($workflow, $overrides): Workflow {
            $source = $workflow->fresh(['steps.assignees', 'steps.actions', 'transitions', 'conditions']);

            $new = $source->replicate(['id', 'uuid', 'created_at', 'updated_at']);
            $new->version = $source->version + 1;
            $new->is_current_version = false;
            $new->status = WorkflowStatus::Draft;
            $new->fill($overrides);
            $new->save();

            $stepIdMap = [];

            foreach ($source->steps as $step) {
                /** @var WorkflowStep $step */
                $newStep = $step->replicate(['id', 'uuid', 'workflow_id', 'created_at', 'updated_at']);
                $newStep->workflow_id = $new->getKey();
                $newStep->save();
                $stepIdMap[$step->getKey()] = $newStep->getKey();

                foreach ($step->assignees as $assignee) {
                    /** @var WorkflowStepAssignee $assignee */
                    $clone = $assignee->replicate(['id', 'uuid', 'step_id', 'created_at', 'updated_at']);
                    $clone->step_id = $newStep->getKey();
                    $clone->save();
                }

                foreach ($step->actions as $action) {
                    /** @var WorkflowStepAction $action */
                    $clone = $action->replicate(['id', 'uuid', 'step_id', 'created_at', 'updated_at']);
                    $clone->step_id = $newStep->getKey();
                    $clone->save();
                }
            }

            foreach ($source->transitions as $transition) {
                /** @var WorkflowTransition $transition */
                $clone = $transition->replicate(['id', 'uuid', 'workflow_id', 'created_at', 'updated_at']);
                $clone->workflow_id = $new->getKey();
                $clone->from_step_id = $transition->from_step_id !== null
                    ? ($stepIdMap[$transition->from_step_id] ?? null)
                    : null;
                $clone->to_step_id = $stepIdMap[$transition->to_step_id] ?? null;
                $clone->save();
            }

            foreach ($source->conditions as $condition) {
                /** @var WorkflowCondition $condition */
                $clone = $condition->replicate(['id', 'uuid', 'workflow_id', 'created_at', 'updated_at']);
                $clone->workflow_id = $new->getKey();
                $clone->save();
            }

            return $new->refresh();
        });
    }

    /**
     * Activate a draft workflow.
     *
     * Validates:
     *   - status must be `draft`  (else {@see InvalidStateException})
     *   - exactly one `start` step
     *   - at least one `end` step
     *   (else {@see InvalidWorkflowException})
     *
     * Then flips `is_current_version` to `true` for this workflow and `false`
     * for the previous active version of the same `(tenant_id, code)` pair.
     *
     * @throws InvalidStateException
     * @throws InvalidWorkflowException
     */
    public function activate(Workflow|string $workflow): Workflow
    {
        $model = $this->resolveWorkflow($workflow);

        if ($model->status !== WorkflowStatus::Draft) {
            throw InvalidStateException::forWorkflow(
                expected: WorkflowStatus::Draft->value,
                actual: $model->status->value,
            );
        }

        $steps = $model->steps()->get();

        if (! WorkflowStateMachine::canActivate($steps)) {
            $starts = $steps->where('type', StepType::Start->value)->count();
            $ends = $steps->where('type', StepType::End->value)->count();
            throw InvalidWorkflowException::invalidGraph(
                "activation requires exactly 1 start step (got {$starts}) and at least 1 end step (got {$ends})",
            );
        }

        return DB::transaction(function () use ($model): Workflow {
            // Flip the previous active version (scoped by tenant + code) to false.
            Workflow::query()
                ->where('code', $model->code)
                ->when($model->tenant_id === null, fn ($q) => $q->whereNull('tenant_id'))
                ->when($model->tenant_id !== null, fn ($q) => $q->where('tenant_id', $model->tenant_id))
                ->where('id', '!=', $model->getKey())
                ->where('is_current_version', true)
                ->update(['is_current_version' => false]);

            $model->is_current_version = true;
            $model->status = WorkflowStatus::Active;
            $model->save();

            return $model->refresh();
        });
    }

    /**
     * Return all versions of a workflow (newest first), scoped by tenant.
     *
     * @return Collection<int, Workflow>
     */
    public function versions(Workflow|string $workflow): Collection
    {
        if (is_string($workflow)) {
            $code = $workflow;
            $tenantId = null;
        } else {
            $code = $workflow->code;
            $tenantId = $workflow->tenant_id;
        }

        return Workflow::query()
            ->where('code', $code)
            ->when($tenantId === null, fn ($q) => $q->whereNull('tenant_id'))
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->orderByDesc('version')
            ->get();
    }

    // -------------------------------------------------------------------
    //  US4 / US6 / US7 — Stubs (filled in by later phases)
    // -------------------------------------------------------------------

    /**
     * Start a new instance of an active workflow bound to a host model.
     *
     * Rejects:
     *   - non-active workflows (InvalidWorkflowException)
     *   - subject whose class does not match the workflow's `subject_type`
     *     (WorkflowSubjectMismatchException)
     *
     * Side effects (single transaction):
     *   - INSERT workflow_instances (status=in_progress, workflow_version
     *     pinned, current_step_id=null initially)
     *   - INSERT workflow_step_instances for the workflow's `start` step
     *     (status=active, entered_at=now, due_at from sla_seconds)
     *   - UPDATE workflow_instances.current_step_id
     *   - APPEND history via HistoryRecorder (event=Started,
     *     actor=initiator or actor_type=system)
     */
    public function start(
        Workflow|string $workflow,
        Model $subject,
        array $context = [],
        mixed $initiator = null,
    ): WorkflowInstance {
        $model = $this->resolveWorkflow($workflow);

        if ($model->status !== WorkflowStatus::Active) {
            throw InvalidWorkflowException::notActive($model->code);
        }

        if ($model->subject_type !== null && $model->subject_type !== '') {
            $expected = ltrim($model->subject_type, '\\');
            $actual = $subject::class;
            if (! is_a($subject, $expected)) {
                throw WorkflowSubjectMismatchException::forWorkflow(
                    $model->code,
                    $expected,
                    $actual,
                );
            }
        }

        $startStep = $model->steps()->where('type', StepType::Start->value)->first();
        if (! $startStep instanceof WorkflowStep) {
            throw InvalidWorkflowException::invalidGraph(
                "Workflow [{$model->code}] has no start step.",
            );
        }
        $startStepType = $startStep->type;

        $instance = DB::transaction(function () use ($model, $subject, $context, $initiator, $startStep): WorkflowInstance {
            $now = Carbon::now();

            $actorId = $initiator !== null && is_object($initiator) && method_exists($initiator, 'getKey')
                ? (int) $initiator->getKey()
                : null;
            $actorType = $initiator !== null ? ActorType::User : ActorType::System;

            $instance = new WorkflowInstance;
            $instance->fill([
                'tenant_id' => $model->tenant_id,
                'workflow_id' => $model->getKey(),
                'workflow_version' => (int) $model->version,
                'subject_type' => $subject::class,
                'subject_id' => (int) $subject->getKey(),
                'status' => InstanceStatus::InProgress,
                'context' => $context,
                'initiated_by' => $actorId,
                'started_at' => $now,
            ]);
            $instance->save();

            $dueAt = $startStep->sla_seconds !== null
                ? $now->copy()->addSeconds((int) $startStep->sla_seconds)
                : null;

            $stepInstance = new WorkflowStepInstance;
            $stepInstance->fill([
                'workflow_instance_id' => $instance->getKey(),
                'step_id' => $startStep->getKey(),
                'status' => StepInstanceStatus::Active,
                'entered_at' => $now,
                'due_at' => $dueAt,
            ]);
            $stepInstance->save();

            $instance->current_step_id = $startStep->getKey();
            $instance->save();

            $this->recorder()->record([
                'tenant_id' => $model->tenant_id,
                'workflow_instance_id' => $instance->getKey(),
                'step_instance_id' => $stepInstance->getKey(),
                'event' => HistoryEvent::Started,
                'actor_id' => $actorId,
                'actor_type' => $actorType,
                'comment' => null,
                'metadata' => ['workflow_code' => $model->code, 'workflow_version' => (int) $model->version],
            ]);

            return $instance->refresh();
        });

        // After the outer transaction commits, drive the chain forward when
        // the entering step is non-human-gated (US5, T058). The runner
        // treats `Start` steps as a structural passthrough when the next
        // step is automated, and chains automated steps until a human-
        // gated step, an `end` step, or a failure. The runner opens its
        // own nested savepoint so a guard/transition failure rolls back
        // cleanly.
        //
        // A `Start` step with `actions` defined is treated as a user-
        // actionable entry point (the user performs the action to advance);
        // the runner is skipped so the start step stays active. A `Start`
        // step whose transition target is `end` (or a human-gated step)
        // is a degenerate single-step workflow and the start step stays
        // active too.
        $runnerIsNeeded = $this->isRunnerNeededForStartStep($startStep);

        if ($runnerIsNeeded) {
            $stepInstance = WorkflowStepInstance::query()
                ->where('workflow_instance_id', $instance->getKey())
                ->where('status', StepInstanceStatus::Active->value)
                ->orderByDesc('id')
                ->first();
            if ($stepInstance instanceof WorkflowStepInstance) {
                $this->automationRunner()->run($instance->refresh(), $stepInstance);
            }
        }

        return $instance->refresh();
    }

    /**
     * Decide whether the AutomationRunner should drive the chain after
     * `start()`. The runner is needed when the entering step is automated
     * OR when the start step is a structural marker leading to an
     * automated step. In all other cases (human-gated entry, actions on
     * the start step, or the start step's only transition goes to a non-
     * automated step), the start step stays active and waits for a user
     * action.
     */
    private function isRunnerNeededForStartStep(WorkflowStep $startStep): bool
    {
        if ($startStep->type === StepType::Automated) {
            return true;
        }

        if ($startStep->type !== StepType::Start) {
            return false;
        }

        // A start step with actions is a user-actionable entry point.
        if ($startStep->actions->isNotEmpty()) {
            return false;
        }

        // Find the next step (excluding the implicit `__start__` self-loop
        // that the engine may create when the user defines
        // `['from' => '__start__', 'to' => 'start']`).
        $transitions = WorkflowTransition::query()
            ->where('workflow_id', $startStep->workflow_id)
            ->where('from_step_id', $startStep->getKey())
            ->where('to_step_id', '!=', $startStep->getKey())
            ->get();

        if ($transitions->isEmpty()) {
            return false;
        }

        $nextStep = $this->transitionResolver()->resolveNextStep($startStep, null, $transitions);

        // Only auto-advance past the start step if the next step is
        // automated. Anything else (end, task, approval, gateway) means
        // the start step is the user's entry point.
        return $nextStep->type === StepType::Automated;
    }

    public function currentStep(WorkflowInstance $instance): WorkflowStepInstance|Collection
    {
        $active = $instance->stepInstances()
            ->where('status', StepInstanceStatus::Active->value)
            ->with('step')
            ->orderBy('id')
            ->cursor();

        if ($active->isEmpty()) {
            // Fallback: instance.current_step_id may still be set even before
            // step_instances are persisted in some flows. Use it.
            if ($instance->current_step_id !== null) {
                $fallback = WorkflowStepInstance::query()
                    ->where('workflow_instance_id', $instance->getKey())
                    ->where('step_id', $instance->current_step_id)
                    ->with('step')
                    ->first();
                if ($fallback instanceof WorkflowStepInstance) {
                    return $fallback;
                }
            }

            return new Collection;
        }

        if ($active->count() === 1) {
            return $active->first();
        }

        return $active->collect();
    }

    /**
     * Resolve the set of actions a user may perform on the instance right now.
     *
     * The result is a deterministic snapshot of the current state, computed by:
     *   1) Eligibility — the step's `authorization_mode` authorizer
     *   2) Per-action availability — `general` always; `conditional` via
     *      `ConditionEvaluator`; `custom` via `CustomActionHandler`
     *   3) Sort: `sort_order ASC`, then `id ASC` (determinism)
     */
    public function availableActions(WorkflowInstance $instance, mixed $user = null): ActionSet
    {
        return $this->actionsResolver()->resolve($instance, $user);
    }

    /**
     * Perform an action and advance the instance.
     *
     * Orchestrator (single transaction):
     *   1) Reject terminal instance (WorkflowTerminalException)
     *   2) Re-validate eligibility server-side
     *   3) Re-resolve `availableActions` and assert the requested `actionCode`
     *      is in the set; if not, throw ActionNotAvailableException
     *   4) Enforce `requires_comment`
     *   5) Close the leaving step instance (status depends on action.type)
     *   6) Invoke the action's handler (if any) via HandlerInvoker
     *   7) Quorum: expire remaining pending assignments when `any` match
     *   8) Resolve the next step (TransitionResolver)
     *   9) Open the entering step instance (or close the instance if end)
     *  10) Materialize assignments for the new step
     *  11) Append `step_completed`, `action_performed`, `step_entered`
     *      (or `completed`) history
     *  12) Return the refreshed instance
     *
     * @throws WorkflowTerminalException
     * @throws NotEligibleException
     * @throws ActionNotAvailableException
     * @throws CommentRequiredException
     * @throws TransitionNotFoundExceptio
     */
    public function perform(
        WorkflowInstance $instance,
        string $actionCode,
        mixed $user = null,
        ?array $payload = null,
    ): WorkflowInstance {
        $instance = $instance->fresh(['stepInstances.step.actions', 'stepInstances.step.assignees', 'workflow']);

        if ($instance->status->isTerminal()) {
            throw WorkflowTerminalException::forInstance($instance->status);
        }

        $current = $this->currentStep($instance);
        if ($current instanceof Collection) {
            $current = $current->first();
        }
        if (! $current instanceof WorkflowStepInstance) {
            throw InvalidStateException::forStepInstance(
                StepInstanceStatus::Active->value,
                $current->status ?? StepInstanceStatus::Active,
            );
        }

        $step = $current->step instanceof WorkflowStep
            ? $current->step
            : WorkflowStep::query()->findOrFail($current->step_id);

        // (2) Re-validate eligibility
        $authorizerRegistry = app()->bound(AuthorizerRegistry::class)
            ? app()->make(AuthorizerRegistry::class)
            : null;
        $authorizer = $authorizerRegistry !== null
            ? $authorizerRegistry->get($step->authorization_mode?->value ?? 'public')
            : new PublicAuthorizer;
        if (! $authorizer->authorize($user, $instance, $current, $step)) {
            $userId = is_object($user) && method_exists($user, 'getKey')
                ? (string) $user->getKey()
                : (string) ($user ?? 'null');
            throw NotEligibleException::forUser($userId, (string) $instance->getKey());
        }

        // (3) Re-resolve and assert actionCode is in the set
        $resolved = $this->actionsResolver()->resolve($instance, $user)->find($actionCode);
        if ($resolved === null) {
            throw ActionNotAvailableException::forAction($actionCode, (string) $instance->getKey());
        }

        $payload = $payload ?? [];
        $comment = isset($payload['comment']) ? (string) $payload['comment'] : null;

        // (4) Enforce requires_comment
        if ($resolved->requiresComment && ($comment === null || trim($comment) === '')) {
            throw CommentRequiredException::forAction($actionCode);
        }

        $actorId = is_object($user) && method_exists($user, 'getKey')
            ? (int) $user->getKey()
            : null;
        $actorType = $actorId !== null ? ActorType::User : ActorType::System;
        $now = Carbon::now();

        // Captured by reference so the after-commit block can chain automation
        // (US5, T058) without reopening the transaction.
        $enteringInstanceRef = null;

        $instance = DB::transaction(function () use (
            $instance, $current, $step, $resolved, $actionCode,
            $payload, $comment, $actorId, $actorType, $now,
            &$enteringInstanceRef,
        ): WorkflowInstance {
            // (5) Close the leaving step instance
            $leavingTerminal = $this->terminalStatusForAction($resolved->type);
            $current->status = $leavingTerminal;
            $current->completed_at = $now;
            $current->acted_by = $actorId;
            $current->action_taken = $actionCode;
            $current->comment = $comment;
            $current->save();

            // Mark the user's pending assignment as acted (if any)
            if ($actorId !== null) {
                WorkflowAssignment::query()
                    ->where('step_instance_id', $current->getKey())
                    ->where('status', AssignmentStatus::Pending)
                    ->where('assignee_id', $actorId)
                    ->update(['status' => AssignmentStatus::Acted, 'acted_at' => $now]);
            }

            // (6) Invoke the action's handler
            $actionModel = WorkflowStepAction::query()
                ->where('step_id', $step->getKey())
                ->where('code', $actionCode)
                ->first();
            if ($actionModel instanceof WorkflowStepAction
                && is_string($actionModel->handler)
                && $actionModel->handler !== ''
            ) {
                $result = $this->handlerInvoker()->invokeAction($actionModel, $instance, $payload);
                if ($result->isFailure()) {
                    // Re-throw the original throwable; the transaction rolls back.
                    throw $result->throwable;
                }
            }

            // (7) Quorum: expire remaining pending assignments for `any` match
            $expired = $this->quorumEvaluator()->expirePending($current->getKey());

            // (8) Resolve the next step
            $transitions = WorkflowTransition::query()
                ->where('workflow_id', $instance->workflow_id)
                ->get();
            $nextStep = $this->transitionResolver()->resolveNextStep($step, $actionModel, $transitions);

            $instance->current_step_id = $nextStep->getKey();
            $instance->save();

            // (9) Open the entering step instance OR close the instance if `end`
            $isTerminalStep = $this->terminalStatusForEnteringStep($nextStep) !== null;
            $enteringInstance = null;

            if ($isTerminalStep) {
                $instance->status = $this->terminalInstanceStatusForAction($resolved->type);
                $instance->completed_at = $now;
                $instance->save();
            } else {
                $dueAt = $nextStep->sla_seconds !== null
                    ? $now->copy()->addSeconds((int) $nextStep->sla_seconds)
                    : null;
                $enteringInstance = new WorkflowStepInstance;
                $enteringInstance->fill([
                    'workflow_instance_id' => $instance->getKey(),
                    'step_id' => $nextStep->getKey(),
                    'status' => StepInstanceStatus::Active,
                    'entered_at' => $now,
                    'due_at' => $dueAt,
                ]);
                $enteringInstance->save();

                // (10) Materialize assignments for task/approval steps
                $this->assignmentMaterializer()->materialize($enteringInstance->getKey());

                // Hand the entering step instance to the outer scope so the
                // post-commit automation kick-off (T058) can drive it.
                $enteringInstanceRef = $enteringInstance;
            }

            // (11) Append history
            $this->recorder()->record([
                'tenant_id' => $instance->tenant_id,
                'workflow_instance_id' => $instance->getKey(),
                'step_instance_id' => $current->getKey(),
                'from_step_id' => $step->getKey(),
                'to_step_id' => $nextStep->getKey(),
                'action_code' => $actionCode,
                'event' => HistoryEvent::StepCompleted,
                'actor_id' => $actorId,
                'actor_type' => $actorType,
                'comment' => $comment,
                'metadata' => ['status' => $leavingTerminal->value],
            ]);

            $this->recorder()->record([
                'tenant_id' => $instance->tenant_id,
                'workflow_instance_id' => $instance->getKey(),
                'step_instance_id' => $current->getKey(),
                'from_step_id' => $step->getKey(),
                'to_step_id' => $nextStep->getKey(),
                'action_code' => $actionCode,
                'event' => HistoryEvent::ActionPerformed,
                'actor_id' => $actorId,
                'actor_type' => $actorType,
                'comment' => null,
                'metadata' => ['resolved_action_code' => $actionCode],
            ]);

            if (! $isTerminalStep) {
                $this->recorder()->record([
                    'tenant_id' => $instance->tenant_id,
                    'workflow_instance_id' => $instance->getKey(),
                    'step_instance_id' => $enteringInstance?->getKey(),
                    'from_step_id' => $step->getKey(),
                    'to_step_id' => $nextStep->getKey(),
                    'event' => HistoryEvent::StepEntered,
                    'actor_id' => $actorId,
                    'actor_type' => $actorType,
                    'comment' => null,
                    'metadata' => ['step_type' => $nextStep->type->value],
                ]);
            } else {
                $this->recorder()->record([
                    'tenant_id' => $instance->tenant_id,
                    'workflow_instance_id' => $instance->getKey(),
                    'event' => HistoryEvent::Completed,
                    'actor_id' => $actorId,
                    'actor_type' => $actorType,
                    'comment' => $comment,
                    'metadata' => ['final_status' => $instance->status->value],
                ]);
            }

            if ($expired !== []) {
                $this->recorder()->record([
                    'tenant_id' => $instance->tenant_id,
                    'workflow_instance_id' => $instance->getKey(),
                    'event' => HistoryEvent::CommentAdded,
                    'actor_id' => $actorId,
                    'actor_type' => $actorType,
                    'comment' => 'Expired pending assignments: '.implode(',', $expired),
                    'metadata' => ['expired_assignment_ids' => $expired, 'match_mode' => MatchMode::Any->value],
                ]);
            }

            return $instance->refresh();
        });

        // After the outer transaction commits: if the entering step is
        // automated, drive the chain forward (US5, T058). The runner opens
        // its own nested savepoint so a guard/transition failure rolls back
        // cleanly without affecting the action we just performed.
        if ($enteringInstanceRef instanceof WorkflowStepInstance) {
            $enteringStep = $enteringInstanceRef->step instanceof WorkflowStep
                ? $enteringInstanceRef->step
                : WorkflowStep::query()->find($enteringInstanceRef->step_id);
            if ($enteringStep instanceof WorkflowStep && $enteringStep->type === StepType::Automated) {
                $this->automationRunner()->run($instance->refresh(), $enteringInstanceRef);
            }
        }

        return $instance->refresh();
    }

    /**
     * Map an action type to the step-instance terminal status.
     */
    private function terminalStatusForAction(ActionType $type): StepInstanceStatus
    {
        return match ($type) {
            ActionType::Reject => StepInstanceStatus::Rejected,
            ActionType::Skip => StepInstanceStatus::Skipped,
            ActionType::Return => StepInstanceStatus::Returned,
            ActionType::Cancel => StepInstanceStatus::Failed,
            default => StepInstanceStatus::Completed,
        };
    }

    /**
     * Map an action type to the workflow-instance terminal status
     * when the entering step is terminal (e.g. `end`).
     */
    private function terminalInstanceStatusForAction(ActionType $type): InstanceStatus
    {
        return match ($type) {
            ActionType::Reject => InstanceStatus::Rejected,
            ActionType::Cancel => InstanceStatus::Cancelled,
            default => InstanceStatus::Completed,
        };
    }

    /**
     * Returns null for non-terminal entering steps, or the terminal
     * step-instance status for terminal ones (e.g. `end`).
     */
    private function terminalStatusForEnteringStep(WorkflowStep $step): ?StepInstanceStatus
    {
        return $step->type === StepType::End ? StepInstanceStatus::Completed : null;
    }

    /**
     * Skip the current step.
     *
     *   1) Reject terminal instance.
     *   2) Reject non-skippable step (SkipNotAllowedException).
     *   3) Find a `type = skip` transition; if it has a guard condition
     *      and the guard fails, reject.
     *   4) Close the leaving step instance with `status = skipped`.
     *   5) Resolve target: explicit `to_step_id` on the skip transition
     *      if present, else next step by ascending `position` (sequential
     *      fallback). Throw TransitionNotFoundException if no target.
     *   6) Open the entering step instance; materialize assignments.
     *   7) Append `skipped` + `step_entered` history.
     *
     * @throws WorkflowTerminalException
     * @throws SkipNotAllowedException
     * @throws TransitionNotFoundException
     */
    public function skip(
        WorkflowInstance $instance,
        mixed $user = null,
        ?string $comment = null,
    ): WorkflowInstance {
        $instance = $instance->fresh(['stepInstances.step', 'stepInstances.step.assignees', 'workflow']);

        if ($instance->status->isTerminal()) {
            throw WorkflowTerminalException::forInstance($instance->status);
        }

        $current = $this->currentStep($instance);
        if ($current instanceof Collection) {
            $current = $current->first();
        }
        if (! $current instanceof WorkflowStepInstance) {
            throw InvalidStateException::forStepInstance(
                StepInstanceStatus::Active->value,
                $current->status ?? StepInstanceStatus::Active,
            );
        }

        $step = $current->step instanceof WorkflowStep
            ? $current->step
            : WorkflowStep::query()->findOrFail($current->step_id);

        // (2) is_skippable flag
        if (! $step->is_skippable) {
            throw SkipNotAllowedException::forStep((string) $step->getKey());
        }

        // (3) Find skip transition (type=skip, from=current)
        $skipTransition = WorkflowTransition::query()
            ->where('workflow_id', $instance->workflow_id)
            ->where('from_step_id', $step->getKey())
            ->where('type', TransitionType::Skip->value)
            ->first();

        // Evaluate the transition's guard (if any) — must pass
        if ($skipTransition instanceof WorkflowTransition
            && $skipTransition->condition_id !== null
        ) {
            $cond = WorkflowCondition::query()->find($skipTransition->condition_id);
            if ($cond instanceof WorkflowCondition) {
                $context = $this->buildConditionContext($instance, $step, $user);
                $payload = (array) ($cond->expression ?? []);
                if (! $this->conditionEvaluator()->evaluate($payload, $context)) {
                    throw SkipNotAllowedException::forStep((string) $step->getKey());
                }
            }
        }

        $actorId = is_object($user) && method_exists($user, 'getKey')
            ? (int) $user->getKey()
            : null;
        $actorType = $actorId !== null ? ActorType::User : ActorType::System;
        $now = Carbon::now();

        return DB::transaction(function () use (
            $instance, $current, $step, $skipTransition, $comment, $actorId, $actorType, $now,
        ): WorkflowInstance {
            // (4) Close the leaving step instance
            $current->status = StepInstanceStatus::Skipped;
            $current->completed_at = $now;
            $current->acted_by = $actorId;
            $current->action_taken = 'skip';
            $current->comment = $comment;
            $current->save();

            // Mark the user's pending assignment as acted
            if ($actorId !== null) {
                WorkflowAssignment::query()
                    ->where('step_instance_id', $current->getKey())
                    ->where('status', AssignmentStatus::Pending)
                    ->where('assignee_id', $actorId)
                    ->update(['status' => AssignmentStatus::Acted, 'acted_at' => $now]);
            }

            // (5) Resolve target step
            $nextStep = null;
            if ($skipTransition instanceof WorkflowTransition && $skipTransition->to_step_id !== null) {
                $nextStep = WorkflowStep::query()->find($skipTransition->to_step_id);
            }

            if (! $nextStep instanceof WorkflowStep) {
                // Sequential fallback: next step by ascending `position`
                $nextStep = WorkflowStep::query()
                    ->where('workflow_id', $instance->workflow_id)
                    ->where('id', '!=', $step->getKey())
                    ->where('position', '>', (int) $step->position)
                    ->orderBy('position')
                    ->first();
            }

            if (! $nextStep instanceof WorkflowStep) {
                throw new TransitionNotFoundException(
                    "No target step for skip from [{$step->code}] (no skip transition, no next-by-position).",
                );
            }

            $instance->current_step_id = $nextStep->getKey();

            // (6) Open the entering step instance OR close the instance if `end`
            $isTerminalStep = $this->terminalStatusForEnteringStep($nextStep) !== null;
            $enteringInstance = null;

            if ($isTerminalStep) {
                $instance->status = InstanceStatus::Completed;
                $instance->completed_at = $now;
                $instance->save();
            } else {
                $dueAt = $nextStep->sla_seconds !== null
                    ? $now->copy()->addSeconds((int) $nextStep->sla_seconds)
                    : null;
                $enteringInstance = new WorkflowStepInstance;
                $enteringInstance->fill([
                    'workflow_instance_id' => $instance->getKey(),
                    'step_id' => $nextStep->getKey(),
                    'status' => StepInstanceStatus::Active,
                    'entered_at' => $now,
                    'due_at' => $dueAt,
                ]);
                $enteringInstance->save();

                $this->assignmentMaterializer()->materialize($enteringInstance->getKey());
                $instance->save();
            }

            // (7) Append history
            $this->recorder()->record([
                'tenant_id' => $instance->tenant_id,
                'workflow_instance_id' => $instance->getKey(),
                'step_instance_id' => $current->getKey(),
                'from_step_id' => $step->getKey(),
                'to_step_id' => $nextStep->getKey(),
                'action_code' => 'skip',
                'event' => HistoryEvent::Skipped,
                'actor_id' => $actorId,
                'actor_type' => $actorType,
                'comment' => $comment,
                'metadata' => ['reason' => 'skipped'],
            ]);

            if (! $isTerminalStep) {
                $this->recorder()->record([
                    'tenant_id' => $instance->tenant_id,
                    'workflow_instance_id' => $instance->getKey(),
                    'step_instance_id' => $enteringInstance?->getKey(),
                    'from_step_id' => $step->getKey(),
                    'to_step_id' => $nextStep->getKey(),
                    'event' => HistoryEvent::StepEntered,
                    'actor_id' => $actorId,
                    'actor_type' => $actorType,
                    'comment' => null,
                    'metadata' => ['step_type' => $nextStep->type->value],
                ]);
            } else {
                $this->recorder()->record([
                    'tenant_id' => $instance->tenant_id,
                    'workflow_instance_id' => $instance->getKey(),
                    'event' => HistoryEvent::Completed,
                    'actor_id' => $actorId,
                    'actor_type' => $actorType,
                    'comment' => $comment,
                    'metadata' => ['final_status' => $instance->status->value],
                ]);
            }

            return $instance->refresh();
        });
    }

    /**
     * Return to an earlier step.
     *
     *   1) Reject terminal instance.
     *   2) Reject non-returnable step (ReturnNotAllowedException).
     *   3) Resolve target: explicit `$targetStep` (WorkflowStep|string)
     *      → that step; else the most recently completed step instance
     *      for this instance; else reject.
     *   4) If a `type = return` transition is present and has a guard,
     *      evaluate it; reject if it fails.
     *   5) Close the current step instance with `status = returned`.
     *   6) Open a NEW active step instance for the target.
     *   7) Materialize assignments.
     *   8) Append `returned` + `step_entered` history. Prior history
     *      rows are never modified.
     *
     * @throws WorkflowTerminalException
     * @throws ReturnNotAllowedException
     */
    public function return(
        WorkflowInstance $instance,
        WorkflowStep|string|null $targetStep = null,
        mixed $user = null,
        ?string $comment = null,
    ): WorkflowInstance {
        $instance = $instance->fresh(['stepInstances.step', 'stepInstances.step.assignees', 'workflow']);

        if ($instance->status->isTerminal()) {
            throw WorkflowTerminalException::forInstance($instance->status);
        }

        $current = $this->currentStep($instance);
        if ($current instanceof Collection) {
            $current = $current->first();
        }
        if (! $current instanceof WorkflowStepInstance) {
            throw InvalidStateException::forStepInstance(
                StepInstanceStatus::Active->value,
                $current->status ?? StepInstanceStatus::Active,
            );
        }

        $step = $current->step instanceof WorkflowStep
            ? $current->step
            : WorkflowStep::query()->findOrFail($current->step_id);

        if (! $step->is_returnable) {
            throw ReturnNotAllowedException::forStep((string) $step->getKey());
        }

        // (3) Resolve target
        $target = $this->resolveReturnTarget($instance, $step, $targetStep);
        if (! $target instanceof WorkflowStep) {
            throw ReturnNotAllowedException::forStep((string) $step->getKey());
        }

        // (4) Evaluate any return-transition guard
        $returnTransition = WorkflowTransition::query()
            ->where('workflow_id', $instance->workflow_id)
            ->where('from_step_id', $step->getKey())
            ->where('to_step_id', $target->getKey())
            ->where('type', TransitionType::Return->value)
            ->first();
        if ($returnTransition instanceof WorkflowTransition && $returnTransition->condition_id !== null) {
            $cond = WorkflowCondition::query()->find($returnTransition->condition_id);
            if ($cond instanceof WorkflowCondition) {
                $context = $this->buildConditionContext($instance, $step, $user);
                $payload = (array) ($cond->expression ?? []);
                if (! $this->conditionEvaluator()->evaluate($payload, $context)) {
                    throw ReturnNotAllowedException::forStep((string) $step->getKey());
                }
            }
        }

        $actorId = is_object($user) && method_exists($user, 'getKey')
            ? (int) $user->getKey()
            : null;
        $actorType = $actorId !== null ? ActorType::User : ActorType::System;
        $now = Carbon::now();

        return DB::transaction(function () use (
            $instance, $current, $step, $target, $comment, $actorId, $actorType, $now,
        ): WorkflowInstance {
            // (5) Close current as returned
            $current->status = StepInstanceStatus::Returned;
            $current->completed_at = $now;
            $current->acted_by = $actorId;
            $current->action_taken = 'return';
            $current->comment = $comment;
            $current->save();

            if ($actorId !== null) {
                WorkflowAssignment::query()
                    ->where('step_instance_id', $current->getKey())
                    ->where('status', AssignmentStatus::Pending)
                    ->where('assignee_id', $actorId)
                    ->update(['status' => AssignmentStatus::Acted, 'acted_at' => $now]);
            }

            // (6) Open a NEW active step instance for the target
            $dueAt = $target->sla_seconds !== null
                ? $now->copy()->addSeconds((int) $target->sla_seconds)
                : null;
            $enteringInstance = new WorkflowStepInstance;
            $enteringInstance->fill([
                'workflow_instance_id' => $instance->getKey(),
                'step_id' => $target->getKey(),
                'status' => StepInstanceStatus::Active,
                'entered_at' => $now,
                'due_at' => $dueAt,
            ]);
            $enteringInstance->save();

            // (7) Materialize assignments
            $this->assignmentMaterializer()->materialize($enteringInstance->getKey());

            $instance->current_step_id = $target->getKey();
            $instance->save();

            // (8) Append history
            $this->recorder()->record([
                'tenant_id' => $instance->tenant_id,
                'workflow_instance_id' => $instance->getKey(),
                'step_instance_id' => $current->getKey(),
                'from_step_id' => $step->getKey(),
                'to_step_id' => $target->getKey(),
                'action_code' => 'return',
                'event' => HistoryEvent::Returned,
                'actor_id' => $actorId,
                'actor_type' => $actorType,
                'comment' => $comment,
                'metadata' => ['returned_to_step_id' => $target->getKey()],
            ]);

            $this->recorder()->record([
                'tenant_id' => $instance->tenant_id,
                'workflow_instance_id' => $instance->getKey(),
                'step_instance_id' => $enteringInstance->getKey(),
                'from_step_id' => $step->getKey(),
                'to_step_id' => $target->getKey(),
                'event' => HistoryEvent::StepEntered,
                'actor_id' => $actorId,
                'actor_type' => $actorType,
                'comment' => null,
                'metadata' => ['step_type' => $target->type->value, 're_entered' => true],
            ]);

            return $instance->refresh();
        });
    }

    /**
     * Resolve the target step for a return action.
     *
     * Priority:
     *   1) Explicit `$targetStep` parameter (WorkflowStep instance or step code string)
     *   2) Most recently completed step instance for this instance
     *   3) Null (caller will throw ReturnNotAllowedException)
     */
    private function resolveReturnTarget(
        WorkflowInstance $instance,
        WorkflowStep $currentStep,
        WorkflowStep|string|null $targetStep,
    ): ?WorkflowStep {
        if ($targetStep instanceof WorkflowStep) {
            return $targetStep;
        }

        if (is_string($targetStep) && $targetStep !== '') {
            $found = WorkflowStep::query()
                ->where('workflow_id', $instance->workflow_id)
                ->where('code', $targetStep)
                ->first();
            if ($found instanceof WorkflowStep) {
                return $found;
            }
        }

        // Default: the most recently completed step instance in this instance
        $recent = WorkflowStepInstance::query()
            ->where('workflow_instance_id', $instance->getKey())
            ->whereIn('status', [
                StepInstanceStatus::Completed->value,
                StepInstanceStatus::Skipped->value,
                StepInstanceStatus::Rejected->value,
            ])
            ->where('step_id', '!=', $currentStep->getKey())
            ->orderByDesc('completed_at')
            ->orderByDesc('id')
            ->first();

        if ($recent instanceof WorkflowStepInstance) {
            $step = $recent->step instanceof WorkflowStep
                ? $recent->step
                : WorkflowStep::query()->find($recent->step_id);
            if ($step instanceof WorkflowStep) {
                return $step;
            }
        }

        return null;
    }

    /**
     * Re-enter the most recently failed step as a fresh step instance and
     * resume the automation chain (US5, T057).
     *
     *   1) Reject non-`failed` instance (InvalidStateException).
     *   2) Find the most recent `failed` step instance.
     *   3) Open a NEW active step instance for that same step.
     *   4) Set the instance back to `in_progress`, clear `completed_at`.
     *   5) Append `step_entered` history.
     *   6) If the re-entered step is `automated`, drive the chain
     *      forward via {@see AutomationRunner} AFTER the transaction
     *      commits (so a deep chain does not race with the open tx).
     *
     * @throws InvalidStateException when the instance is not in `failed`
     * @throws AutomationLoopGuardException when the resumed chain exceeds
     *                                      `config('workflow.automation.max_chain_depth')`
     */
    public function retry(
        WorkflowInstance $instance,
        mixed $user = null,
        ?string $comment = null,
    ): WorkflowInstance {
        $instance = $instance->fresh(['stepInstances.step']);

        if ($instance->status !== InstanceStatus::Failed) {
            throw InvalidStateException::forInstance(
                expected: InstanceStatus::Failed->value,
                actual: $instance->status,
            );
        }

        $failedStepInstance = WorkflowStepInstance::query()
            ->where('workflow_instance_id', $instance->getKey())
            ->where('status', StepInstanceStatus::Failed->value)
            ->orderByDesc('id')
            ->first();

        if (! $failedStepInstance instanceof WorkflowStepInstance) {
            throw InvalidStateException::forInstance(
                expected: 'a failed step instance',
                actual: 'no failed step instance found',
            );
        }

        $step = $failedStepInstance->step instanceof WorkflowStep
            ? $failedStepInstance->step
            : WorkflowStep::query()->findOrFail($failedStepInstance->step_id);

        $actorId = is_object($user) && method_exists($user, 'getKey')
            ? (int) $user->getKey()
            : null;
        $actorType = $actorId !== null ? ActorType::User : ActorType::System;
        $now = Carbon::now();

        $newStepInstance = DB::transaction(function () use (
            $instance, $step, $comment, $actorId, $actorType, $now,
        ): WorkflowStepInstance {
            // (3) Open a NEW active step instance for the failed step.
            $dueAt = $step->sla_seconds !== null
                ? $now->copy()->addSeconds((int) $step->sla_seconds)
                : null;

            $newStepInstance = new WorkflowStepInstance;
            $newStepInstance->fill([
                'workflow_instance_id' => $instance->getKey(),
                'step_id' => $step->getKey(),
                'status' => StepInstanceStatus::Active,
                'entered_at' => $now,
                'due_at' => $dueAt,
            ]);
            $newStepInstance->save();

            // (4) Set the instance back to in_progress, clear completion.
            $instance->status = InstanceStatus::InProgress;
            $instance->current_step_id = $step->getKey();
            $instance->completed_at = null;
            $instance->save();

            // (5) Append `step_entered` history (prior rows are preserved).
            $this->recorder()->record([
                'tenant_id' => $instance->tenant_id,
                'workflow_instance_id' => $instance->getKey(),
                'step_instance_id' => $newStepInstance->getKey(),
                'from_step_id' => $step->getKey(),
                'to_step_id' => $step->getKey(),
                'event' => HistoryEvent::StepEntered,
                'actor_id' => $actorId,
                'actor_type' => $actorType,
                'comment' => $comment,
                'metadata' => ['step_type' => $step->type->value, 'retry' => true],
            ]);

            return $newStepInstance;
        });

        // (6) After commit: if the re-entered step is automated, drive the
        // chain forward so the retry continues until a human-gated step, an
        // `end` step, or a second failure.
        if ($step->type === StepType::Automated) {
            $this->automationRunner()->run($instance->refresh(), $newStepInstance);
        }

        return $instance->refresh();
    }

    public function hold(
        WorkflowInstance $instance,
        mixed $user = null,
        ?string $comment = null,
    ): WorkflowInstance {
        throw InvalidWorkflowException::invalidGraph(
            'WorkflowEngine::hold() will be implemented in US7 (T063-T068).',
        );
    }

    public function resume(
        WorkflowInstance $instance,
        mixed $user = null,
    ): WorkflowInstance {
        throw InvalidWorkflowException::invalidGraph(
            'WorkflowEngine::resume() will be implemented in US7 (T063-T068).',
        );
    }

    public function cancel(
        WorkflowInstance $instance,
        mixed $user = null,
        ?string $comment = null,
    ): WorkflowInstance {
        throw InvalidWorkflowException::invalidGraph(
            'WorkflowEngine::cancel() will be implemented in US7 (T063-T068).',
        );
    }

    public function history(
        WorkflowInstance $instance,
        ?int $limit = null,
        ?string $event = null,
    ): Collection {
        throw InvalidWorkflowException::invalidGraph(
            'WorkflowEngine::history() will be implemented in US6 (T059-T062).',
        );
    }

    // -------------------------------------------------------------------
    //  Internal helpers
    // -------------------------------------------------------------------

    /**
     * @param  list<array<string, mixed>>  $stepRows
     * @return array<string, int> map of step key → step id
     */
    private function createSteps(Workflow $workflow, array $stepRows): array
    {
        $stepIds = [];
        $position = 0;

        foreach ($stepRows as $i => $row) {
            $this->assertStepRow($row, $i);

            $step = new WorkflowStep;
            $step->fill([
                'workflow_id' => $workflow->getKey(),
                'name' => (string) $row['name'],
                'code' => (string) $row['key'],
                'description' => $row['description'] ?? null,
                'type' => $this->coerceEnum(StepType::class, $row['type'], null),
                'position' => (int) ($row['position'] ?? $position++),
                'authorization_mode' => $this->coerceEnum(
                    AuthorizationMode::class,
                    $row['authorization_mode'] ?? null,
                    AuthorizationMode::Public,
                ),
                'match_mode' => $this->coerceEnum(
                    MatchMode::class,
                    $row['match_mode'] ?? null,
                    MatchMode::All,
                ),
                'custom_authorizer' => $row['custom_authorizer'] ?? null,
                'handler' => $row['handler'] ?? null,
                'is_skippable' => (bool) ($row['is_skippable'] ?? false),
                'is_returnable' => (bool) ($row['is_returnable'] ?? false),
                'sla_seconds' => isset($row['sla_seconds']) ? (int) $row['sla_seconds'] : null,
                'config' => $row['config'] ?? null,
            ]);
            $step->save();
            $stepIds[(string) $row['key']] = $step->getKey();

            foreach (($row['assignees'] ?? []) as $j => $assignee) {
                $this->assertAssigneeRow($assignee, $j);
                $a = new WorkflowStepAssignee;
                $a->fill([
                    'step_id' => $step->getKey(),
                    'assignee_type' => (string) $assignee['assignee_type'],
                    'assignee_value' => (string) $assignee['assignee_key'],
                    'weight' => (int) ($assignee['weight'] ?? 0),
                ]);
                $a->save();
            }

            foreach (($row['actions'] ?? []) as $j => $action) {
                $this->assertActionRow($action, $j);
                $act = new WorkflowStepAction;
                $act->fill([
                    'step_id' => $step->getKey(),
                    'code' => (string) $action['code'],
                    'name' => (string) ($action['name'] ?? $action['code']),
                    'type' => $this->coerceEnum(
                        ActionType::class,
                        $action['type'] ?? null,
                        ActionType::Custom,
                    ),
                    'availability_mode' => $this->coerceEnum(
                        ActionAvailabilityMode::class,
                        $action['availability_mode'] ?? null,
                        ActionAvailabilityMode::General,
                    ),
                    'target_step_id' => isset($action['next_step_key']) && $action['next_step_key'] !== null
                        ? ($stepIds[(string) $action['next_step_key']] ?? null)
                        : null,
                    'requires_comment' => (bool) ($action['requires_comment'] ?? false),
                    'handler' => $action['handler'] ?? null,
                    'sort_order' => (int) ($action['sort_order'] ?? $j),
                    'config' => $action['config'] ?? null,
                ]);
                $act->save();
            }
        }

        return $stepIds;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function assertStepRow(mixed $row, int $index): void
    {
        if (! is_array($row)) {
            throw InvalidWorkflowException::invalidGraph("steps[{$index}] must be an array.");
        }
        foreach (['key', 'name', 'type'] as $required) {
            if (! isset($row[$required]) || $row[$required] === '') {
                throw InvalidWorkflowException::invalidGraph("steps[{$index}] missing required field [{$required}].");
            }
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, int>  $stepIds
     */
    private function assertTransitionRow(mixed $row, int $index, array $stepIds): void
    {
        if (! is_array($row)) {
            throw InvalidWorkflowException::invalidGraph("transitions[{$index}] must be an array.");
        }
        foreach (['from', 'to'] as $required) {
            if (! isset($row[$required])) {
                throw InvalidWorkflowException::invalidGraph("transitions[{$index}] missing [{$required}].");
            }
        }
        $from = (string) $row['from'];
        $to = (string) $row['to'];
        if ($from !== '__start__' && ! isset($stepIds[$from])) {
            throw InvalidWorkflowException::invalidGraph("transitions[{$index}].from [{$from}] not found in steps.");
        }
        if (! isset($stepIds[$to])) {
            throw InvalidWorkflowException::invalidGraph("transitions[{$index}].to [{$to}] not found in steps.");
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function assertConditionRow(mixed $row, int $index): void
    {
        if (! is_array($row)) {
            throw InvalidWorkflowException::invalidGraph("conditions[{$index}] must be an array.");
        }
        foreach (['name', 'kind', 'expression'] as $required) {
            if (! array_key_exists($required, $row)) {
                throw InvalidWorkflowException::invalidGraph("conditions[{$index}] missing [{$required}].");
            }
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function assertAssigneeRow(mixed $row, int $index): void
    {
        if (! is_array($row)) {
            throw InvalidWorkflowException::invalidGraph("assignees[{$index}] must be an array.");
        }
        foreach (['assignee_type', 'assignee_key'] as $required) {
            if (! isset($row[$required]) || $row[$required] === '') {
                throw InvalidWorkflowException::invalidGraph("assignees[{$index}] missing [{$required}].");
            }
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function assertActionRow(mixed $row, int $index): void
    {
        if (! is_array($row)) {
            throw InvalidWorkflowException::invalidGraph("actions[{$index}] must be an array.");
        }
        if (! isset($row['code']) || $row['code'] === '') {
            throw InvalidWorkflowException::invalidGraph("actions[{$index}] missing [code].");
        }
    }

    /**
     * @template T of \BackedEnum
     *
     * @param  class-string<T>  $enumClass
     * @return T
     */
    private function coerceEnum(string $enumClass, mixed $value, mixed $default): \BackedEnum
    {
        if ($value === null || $value === '') {
            /** @var T $default */
            return $default;
        }
        if ($value instanceof $enumClass) {
            /** @var T $value */
            return $value;
        }
        if (is_string($value)) {
            /** @var T */
            return $enumClass::from($value);
        }

        throw InvalidWorkflowException::invalidGraph("Invalid enum value for {$enumClass}.");
    }

    private function resolveWorkflow(Workflow|string $workflow): Workflow
    {
        if ($workflow instanceof Workflow) {
            return $workflow;
        }
        $found = Workflow::query()->where('code', $workflow)->latest('version')->first();
        if (! $found) {
            throw InvalidWorkflowException::notActive($workflow);
        }

        return $found;
    }
}
