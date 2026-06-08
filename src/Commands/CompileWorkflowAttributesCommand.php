<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Commands;

use HFlow\LaravelWorkflow\Attributes\Compilation\AttributeCompilerContract;
use HFlow\LaravelWorkflow\Attributes\Compilation\CompileContext;
use HFlow\LaravelWorkflow\Attributes\Compilation\CompiledStep;
use HFlow\LaravelWorkflow\Attributes\Compilation\CompiledWorkflow;
use HFlow\LaravelWorkflow\Enums\WorkflowStatus;
use HFlow\LaravelWorkflow\Exceptions\CompileValidationException;
use HFlow\LaravelWorkflow\Models\Workflow;
use HFlow\LaravelWorkflow\Models\WorkflowCondition;
use HFlow\LaravelWorkflow\Models\WorkflowStep;
use HFlow\LaravelWorkflow\Models\WorkflowStepAction;
use HFlow\LaravelWorkflow\Models\WorkflowStepAssignee;
use HFlow\LaravelWorkflow\Models\WorkflowTransition;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

final class CompileWorkflowAttributesCommand extends Command
{
    protected $signature = 'workflow:compile-attributes
        {--path= : Restrict compile to a file or directory}
        {--dry-run : Parse and validate without writing to the database}
        {--strict : Fail on validation warnings}
        {--no-strict : Continue when validation only emits warnings}
        {--tenant= : Tenant id to scope compiled rows}
        {--workflow-version= : Force a specific workflow version}';

    protected $description = 'Compile PHP workflow attributes into workflow definition tables';

    public function handle(AttributeCompilerContract $compiler): int
    {
        $started = microtime(true);
        $tenant = $this->option('tenant');
        $version = $this->option('workflow-version');

        $context = new CompileContext(
            tenantId: is_string($tenant) && $tenant !== '' ? $tenant : null,
            strict: ! (bool) $this->option('no-strict'),
            version: is_numeric($version) ? (int) $version : null,
            dryRun: (bool) $this->option('dry-run'),
            path: is_string($this->option('path')) ? $this->option('path') : null,
        );

        try {
            $compiled = $compiler->compileAll($context);

            $rows = $context->dryRun
                ? $this->dryRun($compiled)
                : DB::transaction(fn (): array => $this->persistAll($compiled, $context));
        } catch (CompileValidationException $exception) {
            $this->error('Workflow attribute compilation failed validation.');
            foreach ($exception->getViolations() as $violation) {
                $this->line("  {$violation['rule']}  {$violation['message']}");
            }

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($compiled === []) {
            $this->info('No attributed workflows found.');

            return self::SUCCESS;
        }

        $this->info('Workflows compiled: '.count($compiled));
        $this->table(
            ['Code', 'Version', 'Steps', 'Actions', 'Transitions', 'Assignees'],
            array_map(
                fn (CompiledWorkflow $workflow): array => [
                    $workflow->code,
                    (string) $rows[$workflow->code]['version'],
                    (string) count($workflow->steps),
                    (string) $this->actionCount($workflow),
                    (string) count($workflow->transitions),
                    (string) count($workflow->assignees),
                ],
                $compiled,
            ),
        );

        $this->line(sprintf('Time: %.3fs', microtime(true) - $started));

        return self::SUCCESS;
    }

    /**
     * @param  list<CompiledWorkflow>  $workflows
     * @return array<string, array{version: int}>
     */
    private function persistAll(array $workflows, CompileContext $context): array
    {
        $rows = [];

        foreach ($workflows as $workflow) {
            $resolved = $this->resolveVersion($workflow, $context);
            $this->persist($resolved);
            $rows[$workflow->code] = ['version' => $resolved->version];
        }

        return $rows;
    }

    /**
     * @param  list<CompiledWorkflow>  $workflows
     * @return array<string, array{version: int}>
     */
    private function dryRun(array $workflows): array
    {
        $rows = [];

        foreach ($workflows as $workflow) {
            $rows[$workflow->code] = ['version' => $workflow->version];
        }

        return $rows;
    }

    private function resolveVersion(CompiledWorkflow $compiled, CompileContext $context): CompiledWorkflow
    {
        if ($context->version !== null) {
            return $this->withVersion($compiled, $context->version);
        }

        $latest = Workflow::query()
            ->where('code', $compiled->code)
            ->when($compiled->tenantId === null, fn ($query) => $query->whereNull('tenant_id'))
            ->when($compiled->tenantId !== null, fn ($query) => $query->where('tenant_id', $compiled->tenantId))
            ->orderByDesc('version')
            ->first();

        if (! $latest instanceof Workflow) {
            return $this->withVersion($compiled, 1);
        }

        $config = (array) ($latest->config ?? []);
        if (($config['attribute_fingerprint'] ?? null) === $compiled->fingerprint()) {
            return $this->withVersion($compiled, (int) $latest->version);
        }

        return $this->withVersion($compiled, (int) $latest->version + 1);
    }

    private function persist(CompiledWorkflow $compiled): void
    {
        $workflow = Workflow::query()
            ->where('code', $compiled->code)
            ->where('version', $compiled->version)
            ->when($compiled->tenantId === null, fn ($query) => $query->whereNull('tenant_id'))
            ->when($compiled->tenantId !== null, fn ($query) => $query->where('tenant_id', $compiled->tenantId))
            ->first() ?? new Workflow;

        $existingConfig = (array) ($workflow->exists ? $workflow->config : []);
        $workflow->fill([
            'tenant_id' => $compiled->tenantId,
            'code' => $compiled->code,
            'name' => $compiled->name,
            'description' => $compiled->description,
            'type' => $compiled->type,
            'subject_type' => $compiled->subject,
            'version' => $compiled->version,
            'is_current_version' => false,
            'status' => $workflow->exists ? $workflow->status : WorkflowStatus::Draft,
            'require_explicit_transitions' => false,
            'config' => array_merge($existingConfig, [
                'attribute_managed' => true,
                'attribute_fingerprint' => $compiled->fingerprint(),
            ]),
        ]);
        $workflow->save();

        $this->replaceDefinitionRows($workflow, $compiled);
    }

    private function replaceDefinitionRows(Workflow $workflow, CompiledWorkflow $compiled): void
    {
        $oldStepIds = WorkflowStep::query()
            ->where('workflow_id', $workflow->getKey())
            ->pluck('id');

        WorkflowTransition::query()->where('workflow_id', $workflow->getKey())->forceDelete();
        WorkflowStepAction::query()->whereIn('step_id', $oldStepIds)->forceDelete();
        WorkflowStepAssignee::query()->whereIn('step_id', $oldStepIds)->forceDelete();
        WorkflowCondition::query()->where('workflow_id', $workflow->getKey())->forceDelete();
        WorkflowStep::query()->whereIn('id', $oldStepIds)->forceDelete();

        $stepIds = [];
        $actionIds = [];
        $conditionIds = [];

        foreach ($compiled->steps as $step) {
            $model = WorkflowStep::query()->create([
                'tenant_id' => $compiled->tenantId,
                'workflow_id' => $workflow->getKey(),
                'code' => $step->code,
                'name' => $step->name,
                'type' => $step->type,
                'position' => $step->position,
                'authorization_mode' => $step->authorization,
                'match_mode' => $step->matchMode,
                'custom_authorizer' => $step->customAuthorizer,
                'handler' => $step->handler,
                'is_skippable' => $step->isSkippable,
                'is_returnable' => $step->isReturnable,
                'sla_seconds' => $step->slaSeconds,
                'config' => $step->config,
            ]);

            $stepIds[$step->code] = $model->getKey();

            foreach ($step->assignees as $assignee) {
                WorkflowStepAssignee::query()->create([
                    'step_id' => $model->getKey(),
                    'assignee_type' => $assignee['type'],
                    'assignee_value' => $assignee['value'],
                    'custom_resolver' => $assignee['customResolver'],
                    'sort_order' => $assignee['sortOrder'],
                ]);
            }
        }

        foreach ($compiled->conditions as $condition) {
            $conditionIds[$condition['code']] = WorkflowCondition::query()->create([
                'workflow_id' => $workflow->getKey(),
                'code' => $condition['code'],
                'name' => $condition['name'],
                'kind' => $condition['kind'],
                'expression' => $condition['expression'],
                'evaluator' => $condition['evaluator'],
            ])->getKey();
        }

        foreach ($compiled->steps as $step) {
            foreach ($step->actions as $action) {
                $guardConditionId = null;
                if ($action->guardCondition !== null) {
                    $code = "{$step->code}.{$action->code}.guard";
                    $guardConditionId = WorkflowCondition::query()->create([
                        'workflow_id' => $workflow->getKey(),
                        'code' => $code,
                        'name' => "{$step->name} {$action->name} Guard",
                        'kind' => 'expression',
                        'expression' => $action->guardCondition,
                    ])->getKey();
                    $conditionIds[$code] = $guardConditionId;
                }

                $targetStepId = $action->targetStep !== null ? ($stepIds[$action->targetStep] ?? null) : null;
                $model = WorkflowStepAction::query()->create([
                    'step_id' => $stepIds[$step->code],
                    'code' => $action->code,
                    'name' => $action->name,
                    'label' => $action->label,
                    'type' => $action->type,
                    'availability_mode' => $action->availabilityMode,
                    'guard_condition_id' => $guardConditionId,
                    'guard_class' => $action->guardClass,
                    'target_step_id' => $targetStepId,
                    'requires_comment' => $action->requiresComment,
                    'handler' => $action->handler,
                    'sort_order' => $action->sortOrder,
                    'config' => $action->config,
                ]);

                $actionIds["{$step->code}:{$action->code}"] = $model->getKey();
            }
        }

        foreach ($compiled->transitions as $i => $transition) {
            $conditionId = null;
            if ($transition['when'] !== null) {
                $code = "transition.{$transition['from']}.{$transition['to']}.{$i}";
                $conditionId = WorkflowCondition::query()->create([
                    'workflow_id' => $workflow->getKey(),
                    'code' => $code,
                    'name' => "Transition {$transition['from']} to {$transition['to']}",
                    'kind' => 'expression',
                    'expression' => $transition['when'],
                ])->getKey();
                $conditionIds[$code] = $conditionId;
            }

            WorkflowTransition::query()->create([
                'workflow_id' => $workflow->getKey(),
                'from_step_id' => $stepIds[$transition['from']],
                'to_step_id' => $stepIds[$transition['to']],
                'action_id' => $actionIds["{$transition['from']}:{$transition['on']}"] ?? null,
                'condition_id' => $conditionId,
                'type' => $transition['type'],
                'priority' => $transition['priority'],
            ]);
        }

        $startStepId = null;
        foreach ($compiled->steps as $step) {
            if ($step->type === 'start') {
                $startStepId = $stepIds[$step->code];
                break;
            }
        }

        $workflow->start_step_id = $startStepId;
        $workflow->save();
    }

    private function withVersion(CompiledWorkflow $compiled, int $version): CompiledWorkflow
    {
        return new CompiledWorkflow(
            code: $compiled->code,
            name: $compiled->name,
            subject: $compiled->subject,
            type: $compiled->type,
            version: $version,
            tenantId: $compiled->tenantId,
            description: $compiled->description,
            steps: $compiled->steps,
            transitions: $compiled->transitions,
            conditions: $compiled->conditions,
            assignees: $compiled->assignees,
        );
    }

    private function actionCount(CompiledWorkflow $workflow): int
    {
        return array_sum(array_map(
            static fn (CompiledStep $step): int => count($step->actions),
            $workflow->steps,
        ));
    }
}
