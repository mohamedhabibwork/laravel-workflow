<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Engines;

use HFlow\LaravelWorkflow\Actions\ActionSet;
use HFlow\LaravelWorkflow\Contracts\WorkflowEngine as WorkflowEngineContract;
use HFlow\LaravelWorkflow\Enums\ActionAvailabilityMode;
use HFlow\LaravelWorkflow\Enums\ActionType;
use HFlow\LaravelWorkflow\Enums\AuthorizationMode;
use HFlow\LaravelWorkflow\Enums\MatchMode;
use HFlow\LaravelWorkflow\Enums\StepType;
use HFlow\LaravelWorkflow\Enums\TransitionType;
use HFlow\LaravelWorkflow\Enums\WorkflowStatus;
use HFlow\LaravelWorkflow\Enums\WorkflowType;
use HFlow\LaravelWorkflow\Exceptions\InvalidStateException;
use HFlow\LaravelWorkflow\Exceptions\InvalidWorkflowException;
use HFlow\LaravelWorkflow\Models\Workflow;
use HFlow\LaravelWorkflow\Models\WorkflowCondition;
use HFlow\LaravelWorkflow\Models\WorkflowInstance;
use HFlow\LaravelWorkflow\Models\WorkflowStep;
use HFlow\LaravelWorkflow\Models\WorkflowStepAction;
use HFlow\LaravelWorkflow\Models\WorkflowStepAssignee;
use HFlow\LaravelWorkflow\Models\WorkflowStepInstance;
use HFlow\LaravelWorkflow\Models\WorkflowTransition;
use HFlow\LaravelWorkflow\StateMachine\WorkflowStateMachine;
use Illuminate\Database\Eloquent\Model;
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
 * Runtime methods (start, currentStep, availableActions, perform, skip, return,
 * hold, resume, cancel, retry, history) are stubbed with a
 * {@see InvalidWorkflowException} and will be filled in by later phases
 * (US2, US3, US4, US6, US7).
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

            // Wire transitions between steps
            foreach (($definition['transitions'] ?? []) as $i => $row) {
                $this->assertTransitionRow($row, $i, $stepIds);
                WorkflowTransition::query()->create([
                    'workflow_id' => $workflow->getKey(),
                    'from_step_id' => $row['from'] === '__start__' ? null : $stepIds[$row['from']],
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
    //  US2-US7 — Runtime stubs (filled in by later phases)
    // -------------------------------------------------------------------

    public function start(
        Workflow|string $workflow,
        Model $subject,
        array $context = [],
        mixed $initiator = null,
    ): WorkflowInstance {
        throw InvalidWorkflowException::invalidGraph(
            'WorkflowEngine::start() will be implemented in US2 (T026-T031).',
        );
    }

    public function currentStep(WorkflowInstance $instance): WorkflowStepInstance|Collection
    {
        throw InvalidWorkflowException::invalidGraph(
            'WorkflowEngine::currentStep() will be implemented in US3 (T032-T048).',
        );
    }

    public function availableActions(WorkflowInstance $instance, mixed $user = null): ActionSet
    {
        throw InvalidWorkflowException::invalidGraph(
            'WorkflowEngine::availableActions() will be implemented in US3 (T032-T048).',
        );
    }

    public function perform(
        WorkflowInstance $instance,
        string $actionCode,
        mixed $user = null,
        ?array $payload = null,
    ): WorkflowInstance {
        throw InvalidWorkflowException::invalidGraph(
            'WorkflowEngine::perform() will be implemented in US3 (T032-T048).',
        );
    }

    public function skipStep(
        WorkflowInstance $instance,
        ?string $stepKey = null,
        ?string $reason = null,
        mixed $actor = null,
    ): WorkflowInstance {
        throw InvalidWorkflowException::invalidGraph(
            'WorkflowEngine::skipStep() will be implemented in US4 (T049-T053).',
        );
    }

    public function returnToStep(
        WorkflowInstance $instance,
        string $currentStepKey,
        string $targetStepKey,
        ?string $reason = null,
        mixed $actor = null,
    ): WorkflowInstance {
        throw InvalidWorkflowException::invalidGraph(
            'WorkflowEngine::returnToStep() will be implemented in US4 (T049-T053).',
        );
    }

    public function hold(
        WorkflowInstance $instance,
        ?string $reason = null,
        mixed $actor = null,
    ): WorkflowInstance {
        throw InvalidWorkflowException::invalidGraph(
            'WorkflowEngine::hold() will be implemented in US7 (T063-T068).',
        );
    }

    public function resume(
        WorkflowInstance $instance,
        mixed $actor = null,
    ): WorkflowInstance {
        throw InvalidWorkflowException::invalidGraph(
            'WorkflowEngine::resume() will be implemented in US7 (T063-T068).',
        );
    }

    public function cancel(
        WorkflowInstance $instance,
        ?string $reason = null,
        mixed $actor = null,
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
                    'assignee_key' => (string) $assignee['assignee_key'],
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
