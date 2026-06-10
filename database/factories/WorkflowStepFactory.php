<?php

namespace HFlow\LaravelWorkflow\Database\Factories;

use HFlow\LaravelWorkflow\Enums\AuthorizationMode;
use HFlow\LaravelWorkflow\Enums\MatchMode;
use HFlow\LaravelWorkflow\Enums\StepType;
use HFlow\LaravelWorkflow\Models\Workflow;
use HFlow\LaravelWorkflow\Models\WorkflowStep;
use Illuminate\Database\Eloquent\Factories\Factory;

class WorkflowStepFactory extends Factory
{
    protected $model = WorkflowStep::class;

    public function definition(): array
    {
        return [
            'workflow_id' => Workflow::factory(),
            'name' => $this->faker->words(2, true),
            'code' => $this->faker->slug,
            'type' => StepType::Task,
            'position' => $this->faker->numberBetween(1, 10),
            'authorization_mode' => AuthorizationMode::Public,
            'match_mode' => MatchMode::Any,
        ];
    }

    public function start(): self
    {
        return $this->state(fn (array $attributes) => [
            'type' => StepType::Start,
            'position' => 0,
        ]);
    }

    public function end(): self
    {
        return $this->state(fn (array $attributes) => [
            'type' => StepType::End,
            'position' => 100,
        ]);
    }

    public function automated(): self
    {
        return $this->state(fn (array $attributes) => [
            'type' => StepType::Automated,
        ]);
    }
}
