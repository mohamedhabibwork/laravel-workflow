<?php

namespace HFlow\LaravelWorkflow\Services;

use HFlow\LaravelWorkflow\Enums\StepType;
use HFlow\LaravelWorkflow\Enums\WorkflowStatus;
use HFlow\LaravelWorkflow\Models\Workflow;
use HFlow\LaravelWorkflow\Models\WorkflowStep;
use HFlow\LaravelWorkflow\Models\WorkflowStepAction;
use Illuminate\Support\Facades\DB;

class WorkflowService
{
    /**
     * Validate and activate a workflow definition as the current version for its code.
     */
    public function activate(Workflow $workflow): bool
    {
        if ($workflow->status === WorkflowStatus::Active) {
            return true;
        }

        $startStep = $this->validateActivation($workflow);

        return DB::transaction(function () use ($workflow, $startStep) {
            Workflow::where('code', $workflow->code)
                ->whereNot('id', $workflow->id)
                ->update(['is_current_version' => false]);

            $workflow->update([
                'status' => WorkflowStatus::Active,
                'is_current_version' => true,
                'start_step_id' => $startStep->id,
            ]);

            return true;
        });
    }

    /**
     * Ensure a workflow has exactly one start step and at least one end step.
     *
     * @throws \Exception
     */
    public function validateActivation(Workflow $workflow): WorkflowStep
    {
        $steps = $workflow->steps()->get();

        $startSteps = $steps->where('type', StepType::Start);
        $endSteps = $steps->where('type', StepType::End);

        if ($startSteps->count() !== 1) {
            throw new \Exception('A workflow must have exactly one start step.');
        }

        if ($endSteps->count() < 1) {
            throw new \Exception('A workflow must have at least one end step.');
        }

        $startStep = $startSteps->first();

        if (! $startStep instanceof WorkflowStep) {
            throw new \Exception('A workflow must have exactly one start step.');
        }

        return $startStep;
    }

    /**
     * Clone a workflow definition and its structural children into a draft version.
     */
    public function createNewVersion(Workflow $workflow): Workflow
    {
        return DB::transaction(function () use ($workflow) {
            $workflow->loadMissing(['steps.assignees', 'steps.actions', 'transitions']);

            $newWorkflow = $workflow->replicate(['uuid', 'is_current_version', 'status', 'start_step_id']);
            $newWorkflow->version = $workflow->version + 1;
            $newWorkflow->status = WorkflowStatus::Draft;
            $newWorkflow->is_current_version = false;
            $newWorkflow->save();

            $stepMap = [];

            // First pass: clone steps
            foreach ($workflow->steps as $step) {
                $newStep = $step->replicate(['uuid', 'workflow_id']);
                $newStep->workflow_id = $newWorkflow->id;
                $newStep->save();
                $stepMap[$step->id] = $newStep->id;

                // Clone assignees
                foreach ($step->assignees as $assignee) {
                    $newAssignee = $assignee->replicate(['uuid', 'step_id']);
                    $newAssignee->step_id = $newStep->id;
                    $newAssignee->save();
                }

                // Clone actions
                foreach ($step->actions as $action) {
                    $newAction = $action->replicate(['uuid', 'step_id', 'target_step_id']);
                    $newAction->step_id = $newStep->id;
                    $newAction->save();
                }
            }

            // Second pass: clone transitions and fix action targets
            foreach ($workflow->transitions as $transition) {
                $newTransition = $transition->replicate(['uuid', 'workflow_id', 'from_step_id', 'to_step_id', 'action_id']);
                $newTransition->workflow_id = $newWorkflow->id;
                $newTransition->from_step_id = $stepMap[$transition->from_step_id] ?? null;
                $newTransition->to_step_id = $stepMap[$transition->to_step_id] ?? null;

                if ($transition->action_id) {
                    $oldAction = WorkflowStepAction::find($transition->action_id);

                    if (! $oldAction) {
                        continue;
                    }

                    $newStepId = $stepMap[$oldAction->step_id];
                    $newAction = WorkflowStepAction::where('step_id', $newStepId)
                        ->where('code', $oldAction->code)
                        ->first();
                    $newTransition->action_id = $newAction?->id;
                }

                $newTransition->save();
            }

            // Fix action target_step_id
            foreach ($newWorkflow->steps()->get() as $newStep) {
                foreach ($newStep->actions as $newAction) {
                    $oldStepId = array_search($newStep->id, $stepMap, true);

                    if ($oldStepId === false) {
                        continue;
                    }

                    $oldAction = WorkflowStepAction::where('step_id', $oldStepId)->where('code', $newAction->code)->first();

                    if ($oldAction && $oldAction->target_step_id) {
                        $newAction->target_step_id = $stepMap[$oldAction->target_step_id] ?? null;
                        $newAction->save();
                    }
                }
            }

            return $newWorkflow;
        });
    }
}
