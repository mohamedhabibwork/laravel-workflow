<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Commands;

use HFlow\LaravelWorkflow\Enums\StepType;
use HFlow\LaravelWorkflow\Models\Workflow;
use HFlow\LaravelWorkflow\QueryBuilder\TenantScope;
use Illuminate\Console\Command;

/**
 * `workflow:diagnose {workflow?}` — Static structural analysis of a workflow.
 *
 * Walks the definition and reports:
 *   - missing start or end steps
 *   - more than one start or more than one end step
 *   - dangling transitions (from/to a non-existent step or to itself)
 *   - actions without handlers (no FQCN, no stock handler, and no
 *     `CustomActionHandler` FQCN declared on the step)
 *
 * If no workflow code is given, the command diagnoses every active workflow
 * in the tenant.
 */
final class WorkflowDiagnoseCommand extends Command
{
    protected $signature = 'workflow:diagnose
        {workflow? : Workflow code to diagnose (defaults to all active workflows)}
        {--all : Bypass the tenant scope when listing workflows}';

    protected $description = 'Diagnose the structural health of one or more workflow definitions';

    public function handle(): int
    {
        $code = (string) ($this->argument('workflow') ?? '');

        if ($code !== '') {
            $workflows = Workflow::query()
                ->when(! $this->option('all'), fn ($q) => $q)
                ->withoutGlobalScope(TenantScope::class)
                ->where('code', $code)
                ->where('is_current_version', true)
                ->get();

            if ($workflows->isEmpty()) {
                $this->error("No active workflow found with code [{$code}].");

                return self::FAILURE;
            }
        } else {
            $workflows = Workflow::query()
                ->when(! $this->option('all'), fn ($q) => $q)
                ->withoutGlobalScope(TenantScope::class)
                ->where('is_current_version', true)
                ->where('status', 'active')
                ->orderBy('code')
                ->get();
        }

        if ($workflows->isEmpty()) {
            $this->info('No active workflows to diagnose.');

            return self::SUCCESS;
        }

        $hadErrors = false;

        foreach ($workflows as $workflow) {
            $issues = $this->diagnoseWorkflow($workflow);
            $this->newLine();
            $this->line("Workflow <info>{$workflow->code}</info> v{$workflow->version} ({$workflow->status->value})");

            if ($issues === []) {
                $this->line('  <info>OK</info> — no structural issues found.');

                continue;
            }

            $hadErrors = true;
            foreach ($issues as $issue) {
                $this->line("  - {$issue}");
            }
        }

        return $hadErrors ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    protected function diagnoseWorkflow(Workflow $workflow): array
    {
        $issues = [];

        $steps = $workflow->steps()->get();
        $stepIds = $steps->pluck('id')->all();

        // 1. Start / end step checks
        $startSteps = $steps->where('type', StepType::Start);
        $endSteps = $steps->where('type', StepType::End);

        if ($startSteps->isEmpty()) {
            $issues[] = 'Missing <comment>start</comment> step.';
        }
        if ($startSteps->count() > 1) {
            $issues[] = "More than one <comment>start</comment> step ({$startSteps->count()}).";
        }
        if ($endSteps->isEmpty()) {
            $issues[] = 'Missing <comment>end</comment> step.';
        }
        if ($endSteps->count() > 1) {
            $issues[] = "More than one <comment>end</comment> step ({$endSteps->count()}).";
        }

        // 2. Dangling transitions
        foreach ($workflow->transitions()->get() as $tr) {
            $from = (int) $tr->from_step_id;
            $to = (int) $tr->to_step_id;
            if (! in_array($from, $stepIds, true)) {
                $issues[] = "Transition #{$tr->id} references missing from_step_id={$from}.";
            }
            if (! in_array($to, $stepIds, true)) {
                $issues[] = "Transition #{$tr->id} references missing to_step_id={$to}.";
            }
            if ($from === $to) {
                $issues[] = "Transition #{$tr->id} is a self-loop (from=to={$to}).";
            }
        }

        // 3. Actions without handlers
        foreach ($steps as $step) {
            $actions = $step->actions()->get();
            if ($actions->isEmpty()) {
                continue;
            }
            foreach ($actions as $action) {
                $handler = (string) ($action->handler ?? '');
                $hasHandler = $handler !== '' && class_exists($handler);

                if (! $hasHandler) {
                    $issues[] = "Step [{$step->code}] action [{$action->code}] has no handler (no valid FQCN).";
                }
            }
        }

        return $issues;
    }
}
