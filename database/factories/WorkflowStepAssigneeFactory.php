<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Database\Factories;

use HFlow\LaravelWorkflow\Enums\AssigneeType;
use HFlow\LaravelWorkflow\Models\WorkflowStep;
use HFlow\LaravelWorkflow\Models\WorkflowStepAssignee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkflowStepAssignee>
 */
final class WorkflowStepAssigneeFactory extends Factory
{
    protected $model = WorkflowStepAssignee::class;

    public function definition(): array
    {
        return [
            'step_id' => WorkflowStep::factory(),
            'assignee_type' => AssigneeType::User->value,
            'assignee_value' => (string) $this->faker->numberBetween(1, 1000),
            'custom_resolver' => null,
            'sort_order' => 0,
        ];
    }

    public function role(): self
    {
        return $this->state(fn (): array => [
            'assignee_type' => AssigneeType::Role->value,
            'assignee_value' => $this->faker->slug(1),
        ]);
    }

    public function permission(): self
    {
        return $this->state(fn (): array => [
            'assignee_type' => AssigneeType::Permission->value,
            'assignee_value' => $this->faker->slug(2),
        ]);
    }

    public function custom(): self
    {
        return $this->state(fn (): array => [
            'assignee_type' => AssigneeType::Custom->value,
            'custom_resolver' => 'App\\Resolvers\\FakeCustomResolver',
        ]);
    }
}
