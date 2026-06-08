<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Attributes\Compilation;

use HFlow\LaravelWorkflow\Enums\ActionType;
use HFlow\LaravelWorkflow\Enums\MatchMode;
use HFlow\LaravelWorkflow\Enums\StepType;

final class InvariantChecker
{
    /**
     * @return list<array{rule: string, message: string}>
     */
    public function check(CompiledWorkflow $workflow): array
    {
        $violations = [];
        $stepCodes = array_map(static fn (CompiledStep $step): string => $step->code, $workflow->steps);
        $knownSteps = array_flip($stepCodes);

        $startCount = count(array_filter(
            $workflow->steps,
            static fn (CompiledStep $step): bool => $step->type === StepType::Start->value,
        ));
        if ($startCount !== 1) {
            $violations[] = ['rule' => 'V-1', 'message' => "Expected exactly one start step, got {$startCount}."];
        }

        $endCount = count(array_filter(
            $workflow->steps,
            static fn (CompiledStep $step): bool => $step->type === StepType::End->value,
        ));
        if ($endCount < 1) {
            $violations[] = ['rule' => 'V-2', 'message' => 'Expected at least one end step.'];
        }

        $duplicates = array_unique(array_diff_assoc($stepCodes, array_unique($stepCodes)));
        foreach ($duplicates as $duplicate) {
            $violations[] = ['rule' => 'V-5', 'message' => "Duplicate step code [{$duplicate}]."];
        }

        foreach ($workflow->transitions as $transition) {
            if (! isset($knownSteps[$transition['from']])) {
                $violations[] = ['rule' => 'V-3', 'message' => "Transition references missing from step [{$transition['from']}]."];
            }

            if (! isset($knownSteps[$transition['to']])) {
                $violations[] = ['rule' => 'V-4', 'message' => "Transition references missing to step [{$transition['to']}]."];
            }

            $fromStep = $this->stepByCode($workflow, $transition['from']);
            if ($fromStep instanceof CompiledStep && $transition['on'] !== '') {
                $hasAction = collect($fromStep->actions)->contains(
                    static fn (CompiledAction $action): bool => $action->code === $transition['on'],
                );

                if (! $hasAction) {
                    $violations[] = [
                        'rule' => 'V-6',
                        'message' => "Transition from [{$transition['from']}] references missing action [{$transition['on']}].",
                    ];
                }
            }
        }

        foreach ($workflow->steps as $step) {
            if (! in_array($step->matchMode, MatchMode::values(), true)) {
                $violations[] = ['rule' => 'V-8', 'message' => "Step [{$step->code}] has invalid match mode [{$step->matchMode}]."];
            }

            foreach ($step->actions as $action) {
                if ($action->type === ActionType::Reject->value && $action->targetStep === null && ! $action->requiresComment) {
                    $violations[] = [
                        'rule' => 'V-7',
                        'message' => "Reject action [{$action->code}] on step [{$step->code}] must require a comment.",
                    ];
                }

                foreach ([$action->guardClass, $action->handler] as $fqcn) {
                    $this->checkClassExists($violations, $fqcn);
                }
            }

            foreach ([$step->customAuthorizer, $step->handler] as $fqcn) {
                $this->checkClassExists($violations, $fqcn);
            }

            foreach ($step->assignees as $assignee) {
                $this->checkClassExists($violations, $assignee['customResolver'] ?? null);
            }
        }

        foreach ($workflow->conditions as $condition) {
            $this->checkClassExists($violations, $condition['evaluator'] ?? null);
        }

        $this->checkClassExists($violations, $workflow->subject);

        if ((bool) config('workflow.tenancy.enabled', false) && $workflow->tenantId === null) {
            $violations[] = ['rule' => 'V-11', 'message' => 'Tenancy is enabled, but the compiled workflow has no tenant id.'];
        }

        return $violations;
    }

    private function stepByCode(CompiledWorkflow $workflow, string $code): ?CompiledStep
    {
        foreach ($workflow->steps as $step) {
            if ($step->code === $code) {
                return $step;
            }
        }

        return null;
    }

    /**
     * @param  list<array{rule: string, message: string}>  $violations
     */
    private function checkClassExists(array &$violations, ?string $fqcn): void
    {
        if ($fqcn === null || $fqcn === '') {
            return;
        }

        if (class_exists($fqcn) || interface_exists($fqcn)) {
            return;
        }

        $violations[] = ['rule' => 'V-10', 'message' => "Class [{$fqcn}] does not exist."];
    }
}
