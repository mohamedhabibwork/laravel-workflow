<?php

namespace HFlow\LaravelWorkflow\Database\Factories;

use HFlow\LaravelWorkflow\Enums\WorkflowStatus;
use HFlow\LaravelWorkflow\Enums\WorkflowType;
use HFlow\LaravelWorkflow\Models\Workflow;
use Illuminate\Database\Eloquent\Factories\Factory;

class WorkflowFactory extends Factory
{
    protected $model = Workflow::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->words(3, true),
            'code' => $this->faker->unique()->slug,
            'description' => $this->faker->sentence,
            'type' => WorkflowType::Approval,
            'version' => 1,
            'is_current_version' => true,
            'status' => WorkflowStatus::Draft,
        ];
    }

    public function active(): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => WorkflowStatus::Active,
        ]);
    }

    public function automation(): self
    {
        return $this->state(fn (array $attributes) => [
            'type' => WorkflowType::Automation,
        ]);
    }
}
