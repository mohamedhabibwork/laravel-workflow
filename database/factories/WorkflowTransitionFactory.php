<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Database\Factories;

use HFlow\LaravelWorkflow\Enums\TransitionType;
use HFlow\LaravelWorkflow\Models\Workflow;
use HFlow\LaravelWorkflow\Models\WorkflowStep;
use HFlow\LaravelWorkflow\Models\WorkflowTransition;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkflowTransition>
 */
final class WorkflowTransitionFactory extends Factory
{
    protected $model = WorkflowTransition::class;

    public function definition(): array
    {
        return [
            'workflow_id' => Workflow::factory(),
            'from_step_id' => WorkflowStep::factory(),
            'to_step_id' => WorkflowStep::factory(),
            'action_id' => null,
            'condition_id' => null,
            'type' => TransitionType::Forward->value,
            'priority' => 0,
        ];
    }

    public function conditional(): self
    {
        return $this->state(fn (): array => ['type' => TransitionType::Conditional->value]);
    }
}
