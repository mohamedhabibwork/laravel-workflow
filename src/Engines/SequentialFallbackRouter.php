<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Engines;

use HFlow\LaravelWorkflow\Models\WorkflowStep;

final class SequentialFallbackRouter
{
    public function route(WorkflowStep $currentStep): ?WorkflowStep
    {
        $workflow = $currentStep->workflow;
        if ($workflow !== null && (bool) $workflow->require_explicit_transitions) {
            return null;
        }

        $nextStep = WorkflowStep::query()
            ->where('workflow_id', $currentStep->workflow_id)
            ->where('position', '>', (int) $currentStep->position)
            ->orderBy('position')
            ->orderBy('id')
            ->first();

        return $nextStep instanceof WorkflowStep ? $nextStep : null;
    }
}
