<?php

namespace HFlow\LaravelWorkflow\Services;

use HFlow\LaravelWorkflow\Attributes\Action;
use HFlow\LaravelWorkflow\Attributes\Condition;
use HFlow\LaravelWorkflow\Attributes\Query;
use HFlow\LaravelWorkflow\Attributes\Signal;
use HFlow\LaravelWorkflow\Attributes\Step;
use HFlow\LaravelWorkflow\Attributes\Timer;
use HFlow\LaravelWorkflow\Attributes\Transition;
use HFlow\LaravelWorkflow\Attributes\Update;
use HFlow\LaravelWorkflow\Attributes\WorkflowDefinition;
use HFlow\LaravelWorkflow\Models\Workflow;
use HFlow\LaravelWorkflow\Models\WorkflowCondition;
use HFlow\LaravelWorkflow\Models\WorkflowStep;
use HFlow\LaravelWorkflow\Models\WorkflowStepAction;
use HFlow\LaravelWorkflow\Models\WorkflowTransition;
use Illuminate\Support\Facades\DB;
use ReflectionClass;

class AttributeWorkflowRegistrar
{
    public function __construct(
        protected WorkflowService $workflowService,
    ) {}

    /**
     * Sync one attributed workflow definition class into database-backed models.
     *
     * @param  class-string|object  $workflowClass
     */
    public function sync(string|object $workflowClass, bool $activate = false): Workflow
    {
        $reflection = new ReflectionClass($workflowClass);
        $definition = $this->definitionFor($reflection);

        return DB::transaction(function () use ($reflection, $definition, $activate) {
            $workflow = Workflow::query()->updateOrCreate([
                'code' => $definition->code,
                'version' => $definition->version,
            ], [
                'name' => $definition->name,
                'description' => $definition->description,
                'type' => $definition->type,
                'subject_type' => $definition->subjectType,
                'is_current_version' => $definition->isCurrentVersion,
                'status' => $definition->status,
                'require_explicit_transitions' => $definition->requireExplicitTransitions,
                'config' => $this->runtimeConfigFor($reflection, $definition->config),
            ]);

            if ($workflow->is_current_version) {
                Workflow::query()
                    ->where('code', $workflow->code)
                    ->whereNot('id', $workflow->id)
                    ->update(['is_current_version' => false]);
            }

            $conditions = $this->syncConditions($reflection, $workflow);
            $steps = $this->syncSteps($reflection, $workflow);
            $actions = $this->syncActions($reflection, $workflow, $steps, $conditions);
            $this->syncTransitions($reflection, $workflow, $steps, $actions, $conditions);

            $startStep = collect($steps)->first(fn (WorkflowStep $step) => $step->type->value === 'start');

            if ($startStep instanceof WorkflowStep) {
                $workflow->update(['start_step_id' => $startStep->id]);
            }

            if ($activate || $definition->activate) {
                $this->workflowService->activate($workflow->fresh());
            }

            return $workflow->fresh();
        });
    }

    /**
     * Sync all configured attribute workflow classes.
     *
     * @return array<class-string, Workflow>
     */
    public function syncConfigured(bool $activate = false): array
    {
        $workflows = [];

        foreach (config('workflow.attributes.workflows', []) as $workflowClass) {
            if (! is_string($workflowClass) || ! class_exists($workflowClass)) {
                throw new \Exception('Configured attribute workflow class does not exist.');
            }

            $workflows[$workflowClass] = $this->sync($workflowClass, $activate);
        }

        return $workflows;
    }

    protected function definitionFor(ReflectionClass $reflection): WorkflowDefinition
    {
        $attributes = $reflection->getAttributes(WorkflowDefinition::class);

        if ($attributes === []) {
            throw new \Exception("Workflow class '{$reflection->getName()}' is missing the WorkflowDefinition attribute.");
        }

        return $attributes[0]->newInstance();
    }

    /**
     * @return array<string, WorkflowCondition>
     */
    protected function syncConditions(ReflectionClass $reflection, Workflow $workflow): array
    {
        $conditions = [];

        foreach ($this->attributesFor($reflection, Condition::class) as $condition) {
            $conditions[$condition->code] = WorkflowCondition::query()->updateOrCreate([
                'workflow_id' => $workflow->id,
                'code' => $condition->code,
            ], [
                'name' => $condition->name,
                'kind' => $condition->kind,
                'expression' => $condition->expression,
                'evaluator' => $condition->evaluator,
            ]);
        }

        return $conditions;
    }

    /**
     * @return array<string, WorkflowStep>
     */
    protected function syncSteps(ReflectionClass $reflection, Workflow $workflow): array
    {
        $steps = [];

        foreach ($this->attributesFor($reflection, Step::class) as $step) {
            $steps[$step->code] = WorkflowStep::query()->updateOrCreate([
                'workflow_id' => $workflow->id,
                'code' => $step->code,
            ], [
                'name' => $step->name,
                'description' => $step->description,
                'type' => $step->type,
                'position' => $step->position,
                'authorization_mode' => $step->authorizationMode,
                'match_mode' => $step->matchMode,
                'custom_authorizer' => $step->customAuthorizer,
                'handler' => $step->handler,
                'is_skippable' => $step->isSkippable,
                'is_returnable' => $step->isReturnable,
                'sla_seconds' => $step->slaSeconds,
                'config' => $step->config,
            ]);
        }

        return $steps;
    }

    /**
     * @param  array<string, WorkflowStep>  $steps
     * @param  array<string, WorkflowCondition>  $conditions
     * @return array<string, WorkflowStepAction>
     */
    protected function syncActions(ReflectionClass $reflection, Workflow $workflow, array $steps, array $conditions): array
    {
        $actions = [];

        foreach ($this->attributesFor($reflection, Action::class) as $action) {
            $step = $steps[$action->step] ?? null;

            if (! $step instanceof WorkflowStep) {
                throw new \Exception("Action '{$action->code}' references missing step '{$action->step}'.");
            }

            $targetStep = $action->targetStep ? ($steps[$action->targetStep] ?? null) : null;
            $guardCondition = $action->guardCondition ? ($conditions[$action->guardCondition] ?? null) : null;

            if ($action->targetStep && ! $targetStep instanceof WorkflowStep) {
                throw new \Exception("Action '{$action->code}' references missing target step '{$action->targetStep}'.");
            }

            if ($action->guardCondition && ! $guardCondition instanceof WorkflowCondition) {
                throw new \Exception("Action '{$action->code}' references missing guard condition '{$action->guardCondition}'.");
            }

            $key = "{$action->step}:{$action->code}";
            $actions[$key] = WorkflowStepAction::query()->updateOrCreate([
                'step_id' => $step->id,
                'code' => $action->code,
            ], [
                'name' => $action->name ?? str($action->code)->headline()->toString(),
                'label' => $action->label,
                'type' => $action->type,
                'availability_mode' => $action->availabilityMode,
                'guard_condition_id' => $guardCondition?->id,
                'guard_class' => $action->guardClass,
                'target_step_id' => $targetStep?->id,
                'requires_comment' => $action->requiresComment,
                'handler' => $action->handler,
                'sort_order' => $action->sortOrder,
            ]);
        }

        return $actions;
    }

    /**
     * @param  array<string, WorkflowStep>  $steps
     * @param  array<string, WorkflowStepAction>  $actions
     * @param  array<string, WorkflowCondition>  $conditions
     */
    protected function syncTransitions(ReflectionClass $reflection, Workflow $workflow, array $steps, array $actions, array $conditions): void
    {
        foreach ($this->attributesFor($reflection, Transition::class) as $transition) {
            $fromStep = $steps[$transition->from] ?? null;
            $toStep = $steps[$transition->to] ?? null;
            $condition = $transition->condition ? ($conditions[$transition->condition] ?? null) : null;
            $action = $transition->action ? ($actions["{$transition->from}:{$transition->action}"] ?? null) : null;

            if (! $fromStep instanceof WorkflowStep || ! $toStep instanceof WorkflowStep) {
                throw new \Exception("Transition from '{$transition->from}' to '{$transition->to}' references a missing step.");
            }

            if ($transition->condition && ! $condition instanceof WorkflowCondition) {
                throw new \Exception("Transition references missing condition '{$transition->condition}'.");
            }

            if ($transition->action && ! $action instanceof WorkflowStepAction) {
                throw new \Exception("Transition references missing action '{$transition->action}' on step '{$transition->from}'.");
            }

            WorkflowTransition::query()->updateOrCreate([
                'workflow_id' => $workflow->id,
                'from_step_id' => $fromStep->id,
                'to_step_id' => $toStep->id,
                'action_id' => $action?->id,
            ], [
                'condition_id' => $condition?->id,
                'type' => $transition->type,
                'priority' => $transition->priority,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    protected function runtimeConfigFor(ReflectionClass $reflection, array $config): array
    {
        foreach ($this->attributesFor($reflection, Signal::class) as $signal) {
            $config['signals'][$signal->name] = $signal->handler;
        }

        foreach ($this->attributesFor($reflection, Update::class) as $update) {
            $config['updates'][$update->name] = $update->handler;

            if ($update->validator) {
                $config['update_validators'][$update->name] = $update->validator;
            }
        }

        foreach ($this->attributesFor($reflection, Query::class) as $query) {
            $config['queries'][$query->name] = $query->handler;
        }

        foreach ($this->attributesFor($reflection, Timer::class) as $timer) {
            $config['timers'][$timer->name] = $timer->handler;
        }

        return $config;
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $attributeClass
     * @return array<int, T>
     */
    protected function attributesFor(ReflectionClass $reflection, string $attributeClass): array
    {
        $attributes = array_map(
            fn (\ReflectionAttribute $attribute) => $attribute->newInstance(),
            $reflection->getAttributes($attributeClass)
        );

        foreach ($reflection->getMethods() as $method) {
            foreach ($method->getAttributes($attributeClass) as $attribute) {
                $attributes[] = $attribute->newInstance();
            }
        }

        return $attributes;
    }
}
