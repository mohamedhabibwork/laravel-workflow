<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Database\Factories;

use HFlow\LaravelWorkflow\Enums\StepInstanceStatus;
use HFlow\LaravelWorkflow\Models\WorkflowInstance;
use HFlow\LaravelWorkflow\Models\WorkflowStep;
use HFlow\LaravelWorkflow\Models\WorkflowStepInstance;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkflowStepInstance>
 */
final class WorkflowStepInstanceFactory extends Factory
{
    protected $model = WorkflowStepInstance::class;

    public function definition(): array
    {
        return [
            'workflow_instance_id' => WorkflowInstance::factory(),
            'step_id' => WorkflowStep::factory(),
            'status' => StepInstanceStatus::Active->value,
            'entered_at' => now(),
            'completed_at' => null,
            'due_at' => null,
            'acted_by' => null,
            'action_taken' => null,
            'comment' => null,
            'data' => null,
        ];
    }

    public function completed(): self
    {
        return $this->state(fn (): array => [
            'status' => StepInstanceStatus::Completed->value,
            'completed_at' => now(),
        ]);
    }

    public function skipped(): self
    {
        return $this->state(fn (): array => [
            'status' => StepInstanceStatus::Skipped->value,
            'completed_at' => now(),
        ]);
    }
}
