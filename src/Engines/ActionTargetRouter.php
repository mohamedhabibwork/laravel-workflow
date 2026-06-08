<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Engines;

use HFlow\LaravelWorkflow\Models\WorkflowStep;
use HFlow\LaravelWorkflow\Models\WorkflowStepAction;

final class ActionTargetRouter
{
    public function route(?WorkflowStepAction $action): ?WorkflowStep
    {
        if ($action === null || $action->target_step_id === null) {
            return null;
        }

        $target = WorkflowStep::query()->find($action->target_step_id);

        return $target instanceof WorkflowStep ? $target : null;
    }
}
