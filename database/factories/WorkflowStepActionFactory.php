<?php

namespace HFlow\LaravelWorkflow\Database\Factories;

use HFlow\LaravelWorkflow\Enums\ActionType;
use HFlow\LaravelWorkflow\Enums\AvailabilityMode;
use HFlow\LaravelWorkflow\Models\WorkflowStep;
use HFlow\LaravelWorkflow\Models\WorkflowStepAction;
use Illuminate\Database\Eloquent\Factories\Factory;

class WorkflowStepActionFactory extends Factory
{
    protected $model = WorkflowStepAction::class;

    public function definition(): array
    {
        return [
            'step_id' => WorkflowStep::factory(),
            'name' => $this->faker->words(2, true),
            'code' => $this->faker->slug,
            'label' => $this->faker->word,
            'type' => ActionType::Submit,
            'availability_mode' => AvailabilityMode::General,
            'requires_comment' => false,
            'sort_order' => 0,
        ];
    }
}
