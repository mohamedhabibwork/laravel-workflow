<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Database\Factories;

use HFlow\LaravelWorkflow\Enums\AssignmentStatus;
use HFlow\LaravelWorkflow\Models\WorkflowAssignment;
use HFlow\LaravelWorkflow\Models\WorkflowStepInstance;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkflowAssignment>
 */
final class WorkflowAssignmentFactory extends Factory
{
    protected $model = WorkflowAssignment::class;

    public function definition(): array
    {
        return [
            'step_instance_id' => WorkflowStepInstance::factory(),
            'assignee_id' => (string) $this->faker->numberBetween(1, 1000),
            'status' => AssignmentStatus::Pending->value,
            'assigned_at' => now(),
            'acted_at' => null,
        ];
    }

    public function acted(): self
    {
        return $this->state(fn (): array => [
            'status' => AssignmentStatus::Acted->value,
            'acted_at' => now(),
        ]);
    }

    public function expired(): self
    {
        return $this->state(fn (): array => ['status' => AssignmentStatus::Expired->value]);
    }
}
