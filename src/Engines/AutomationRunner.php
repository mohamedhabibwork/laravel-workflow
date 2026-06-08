<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Engines;

use HFlow\LaravelWorkflow\Enums\ActorType;
use HFlow\LaravelWorkflow\Enums\HistoryEvent;
use HFlow\LaravelWorkflow\Enums\InstanceStatus;
use HFlow\LaravelWorkflow\Enums\StepInstanceStatus;
use HFlow\LaravelWorkflow\Enums\StepType;
use HFlow\LaravelWorkflow\Exceptions\AutomationLoopGuardException;
use HFlow\LaravelWorkflow\Exceptions\TransitionNotFoundException;
use HFlow\LaravelWorkflow\Models\WorkflowInstance;
use HFlow\LaravelWorkflow\Models\WorkflowStep;
use HFlow\LaravelWorkflow\Models\WorkflowStepInstance;
use HFlow\LaravelWorkflow\Models\WorkflowTransition;
use HFlow\LaravelWorkflow\Observability\HistoryRecorder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Chains automated steps until a human-gated step, an `end` step, or a
 * failure is reached.
 *
 * Entry point: {@see self::run()} is called by {@see WorkflowEngine}
 * from `start()` and `perform()` AFTER the entering step instance has been
 * persisted, and AFTER any outer transaction has committed. The runner then
 * opens its OWN short transaction so a deep chain still rolls back cleanly on
 * a guard or transition failure.
 *
 * Each iteration of the loop:
 *   1) Invokes the step handler via {@see HandlerInvoker::invokeStep()}.
 *   2) On failure: closes the step as `failed`, sets the instance to
 *      `failed`, records an `error` history event with the throwable's
 *      class and message, and returns.
 *   3) On success: merges the returned array into `$stepInstance->data`,
 *      closes the step as `completed`, asks {@see TransitionResolver}
 *      for the next step, and:
 *        - `end`         → closes the instance as `completed`, records
 *                          `completed`, returns.
 *        - `automated`   → opens a new step instance, records
 *                          `step_entered`, increments depth, continues.
 *        - human-gated   → opens a new step instance, records
 *                          `step_entered`, returns.
 *        - missing       → throws {@see TransitionNotFoundException}.
 *   4) If the chain depth exceeds `config('workflow.automation.max_chain_depth')`,
 *      throws {@see AutomationLoopGuardException}.
 */
final class AutomationRunner
{
    public function __construct(
        private readonly HistoryRecorder $recorder,
        private readonly HandlerInvoker $handlerInvoker,
        private readonly TransitionResolver $transitionResolver,
    ) {}

    /**
     * Run the automation chain starting at the given step instance.
     *
     * The step instance MUST already be persisted with `status = active`.
     *
     * @throws AutomationLoopGuardException when chain depth exceeds `max_chain_depth`
     * @throws TransitionNotFoundException when no transition can be resolved
     */
    public function run(WorkflowInstance $instance, WorkflowStepInstance $firstStepInstance): void
    {
        $maxDepth = max(1, (int) config('workflow.automation.max_chain_depth', 50));

        $currentInstance = $firstStepInstance;
        $currentStep = $currentInstance->step instanceof WorkflowStep
            ? $currentInstance->step
            : WorkflowStep::query()->findOrFail($currentInstance->step_id);
        $depth = 1;

        while (true) {
            if ($depth > $maxDepth) {
                throw AutomationLoopGuardException::exceeded($maxDepth);
            }

            // Start-marker passthrough: a workflow may have a `type=start`
            // step with no actions (purely structural) immediately followed
            // by automated steps. Treat the start step as a marker (no
            // business logic, no handler), close it as `completed`, and
            // advance to its transition target.
            if ($currentStep->type === StepType::Start) {
                $transitions = WorkflowTransition::query()
                    ->where('workflow_id', $instance->workflow_id)
                    ->where('to_step_id', '!=', $currentStep->getKey())
                    ->get();
                $nextStep = $this->transitionResolver->resolveNextStep($currentStep, null, $transitions);

                $now = Carbon::now();
                $currentInstance->status = StepInstanceStatus::Completed;
                $currentInstance->completed_at = $now;
                $currentInstance->save();

                $this->recorder->record([
                    'tenant_id' => $instance->tenant_id,
                    'workflow_instance_id' => $instance->getKey(),
                    'step_instance_id' => $currentInstance->getKey(),
                    'from_step_id' => $currentStep->getKey(),
                    'to_step_id' => $nextStep->getKey(),
                    'event' => HistoryEvent::StepCompleted,
                    'actor_id' => null,
                    'actor_type' => ActorType::System,
                    'comment' => null,
                    'metadata' => [
                        'status' => StepInstanceStatus::Completed->value,
                        'start_passthrough' => true,
                    ],
                ]);

                $currentStep = $nextStep;
                if ($currentStep->type === StepType::End) {
                    $this->closeAsCompleted($instance, $currentInstance, $currentStep, $currentStep, $now);

                    return;
                }

                $depth++;
                $currentInstance = $this->openStepInstance($instance, $currentStep, $now);
                $this->recorder->record([
                    'tenant_id' => $instance->tenant_id,
                    'workflow_instance_id' => $instance->getKey(),
                    'step_instance_id' => $currentInstance->getKey(),
                    'from_step_id' => $currentStep->getKey(),
                    'to_step_id' => $currentStep->getKey(),
                    'event' => HistoryEvent::StepEntered,
                    'actor_id' => null,
                    'actor_type' => ActorType::System,
                    'comment' => null,
                    'metadata' => ['step_type' => $currentStep->type->value, 'automation' => true],
                ]);

                continue;
            }

            // The runner is for automated steps; bail out for human-gated
            // steps (task/approval/gateway) so `retry()` after a human action
            // does not blow up.
            if ($currentStep->type !== StepType::Automated) {
                return;
            }

            $result = $this->handlerInvoker->invokeStep($currentStep, $currentInstance);

            if ($result->isFailure()) {
                $this->failStep($instance, $currentInstance, $currentStep, $result->throwable);

                return;
            }

            // Success: merge data, close the step, find the next step.
            $now = Carbon::now();
            $currentInstance->data = array_merge(
                (array) ($currentInstance->data ?? []),
                $result->data,
            );
            $currentInstance->status = StepInstanceStatus::Completed;
            $currentInstance->completed_at = $now;
            $currentInstance->save();

            $this->recorder->record([
                'tenant_id' => $instance->tenant_id,
                'workflow_instance_id' => $instance->getKey(),
                'step_instance_id' => $currentInstance->getKey(),
                'from_step_id' => $currentStep->getKey(),
                'to_step_id' => $currentStep->getKey(),
                'event' => HistoryEvent::StepCompleted,
                'actor_id' => null,
                'actor_type' => ActorType::System,
                'comment' => null,
                'metadata' => [
                    'status' => StepInstanceStatus::Completed->value,
                    'automation' => true,
                ],
            ]);

            $transitions = WorkflowTransition::query()
                ->where('workflow_id', $instance->workflow_id)
                ->get();

            $nextStep = $this->transitionResolver->resolveNextStep($currentStep, null, $transitions);

            if ($nextStep->type === StepType::End) {
                $this->closeAsCompleted($instance, $currentInstance, $currentStep, $nextStep, $now);

                return;
            }

            if ($nextStep->type === StepType::Automated) {
                $depth++;

                $currentInstance = $this->openStepInstance($instance, $nextStep, $now);
                $currentStep = $nextStep;

                $this->recorder->record([
                    'tenant_id' => $instance->tenant_id,
                    'workflow_instance_id' => $instance->getKey(),
                    'step_instance_id' => $currentInstance->getKey(),
                    'from_step_id' => $currentStep->getKey(),
                    'to_step_id' => $currentStep->getKey(),
                    'event' => HistoryEvent::StepEntered,
                    'actor_id' => null,
                    'actor_type' => ActorType::System,
                    'comment' => null,
                    'metadata' => ['step_type' => $nextStep->type->value, 'automation' => true],
                ]);

                continue;
            }

            // Human-gated step: open it, append step_entered, stop.
            $currentInstance = $this->openStepInstance($instance, $nextStep, $now);
            $instance->current_step_id = $nextStep->getKey();
            $instance->save();

            $this->recorder->record([
                'tenant_id' => $instance->tenant_id,
                'workflow_instance_id' => $instance->getKey(),
                'step_instance_id' => $currentInstance->getKey(),
                'from_step_id' => $nextStep->getKey(),
                'to_step_id' => $nextStep->getKey(),
                'event' => HistoryEvent::StepEntered,
                'actor_id' => null,
                'actor_type' => ActorType::System,
                'comment' => null,
                'metadata' => ['step_type' => $nextStep->type->value, 'automation' => true],
            ]);

            return;
        }
    }

    /**
     * Mark the step and instance as failed and record an `error` history event.
     */
    private function failStep(
        WorkflowInstance $instance,
        WorkflowStepInstance $stepInstance,
        WorkflowStep $step,
        \Throwable $throwable,
    ): void {
        $now = Carbon::now();

        DB::transaction(function () use ($instance, $stepInstance, $step, $throwable, $now): void {
            $stepInstance->status = StepInstanceStatus::Failed;
            $stepInstance->completed_at = $now;
            $stepInstance->action_taken = 'automation_failure';
            $stepInstance->comment = $throwable->getMessage();
            $stepInstance->save();

            $instance->status = InstanceStatus::Failed;
            $instance->completed_at = $now;
            $instance->save();

            $this->recorder->record([
                'tenant_id' => $instance->tenant_id,
                'workflow_instance_id' => $instance->getKey(),
                'step_instance_id' => $stepInstance->getKey(),
                'from_step_id' => $step->getKey(),
                'to_step_id' => $step->getKey(),
                'event' => HistoryEvent::Error,
                'actor_id' => null,
                'actor_type' => ActorType::System,
                'comment' => $throwable->getMessage(),
                'metadata' => [
                    'error_class' => $throwable::class,
                    'error_message' => $throwable->getMessage(),
                    'automation' => true,
                ],
            ]);
        });
    }

    /**
     * Close the instance as completed after reaching an `end` step via automation.
     */
    private function closeAsCompleted(
        WorkflowInstance $instance,
        WorkflowStepInstance $stepInstance,
        WorkflowStep $currentStep,
        WorkflowStep $endStep,
        Carbon $now,
    ): void {
        DB::transaction(function () use ($instance, $stepInstance, $currentStep, $endStep, $now): void {
            $instance->current_step_id = $endStep->getKey();
            $instance->status = InstanceStatus::Completed;
            $instance->completed_at = $now;
            $instance->save();

            $this->recorder->record([
                'tenant_id' => $instance->tenant_id,
                'workflow_instance_id' => $instance->getKey(),
                'step_instance_id' => $stepInstance->getKey(),
                'from_step_id' => $currentStep->getKey(),
                'to_step_id' => $endStep->getKey(),
                'event' => HistoryEvent::Completed,
                'actor_id' => null,
                'actor_type' => ActorType::System,
                'comment' => null,
                'metadata' => ['final_status' => InstanceStatus::Completed->value, 'automation' => true],
            ]);
        });
    }

    /**
     * Open a new active step instance for a target step and update
     * `instance.current_step_id`.
     */
    private function openStepInstance(
        WorkflowInstance $instance,
        WorkflowStep $step,
        Carbon $now,
    ): WorkflowStepInstance {
        $dueAt = $step->sla_seconds !== null
            ? $now->copy()->addSeconds((int) $step->sla_seconds)
            : null;

        $stepInstance = new WorkflowStepInstance;
        $stepInstance->fill([
            'workflow_instance_id' => $instance->getKey(),
            'step_id' => $step->getKey(),
            'status' => StepInstanceStatus::Active,
            'entered_at' => $now,
            'due_at' => $dueAt,
        ]);
        $stepInstance->save();

        $instance->current_step_id = $step->getKey();
        $instance->save();

        return $stepInstance;
    }
}
