<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Database\Factories;

use HFlow\LaravelWorkflow\Enums\ActionAvailabilityMode;
use HFlow\LaravelWorkflow\Enums\ActionType;
use HFlow\LaravelWorkflow\Models\WorkflowStep;
use HFlow\LaravelWorkflow\Models\WorkflowStepAction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkflowStepAction>
 */
final class WorkflowStepActionFactory extends Factory
{
    protected $model = WorkflowStepAction::class;

    public function definition(): array
    {
        return [
            'step_id' => WorkflowStep::factory(),
            'name' => $this->faker->words(2, true),
            'code' => $this->faker->unique()->slug(2),
            'label' => $this->faker->words(2, true),
            'type' => ActionType::Approve->value,
            'availability_mode' => ActionAvailabilityMode::General->value,
            'guard_condition_id' => null,
            'guard_class' => null,
            'target_step_id' => null,
            'requires_comment' => false,
            'handler' => null,
            'sort_order' => 0,
        ];
    }

    public function reject(): self
    {
        return $this->state(fn (): array => ['type' => ActionType::Reject->value]);
    }

    public function custom(): self
    {
        return $this->state(fn (): array => [
            'type' => ActionType::Custom->value,
            'handler' => 'App\\Actions\\FakeCustomActionHandler',
        ]);
    }

    public function conditional(): self
    {
        return $this->state(fn (): array => ['availability_mode' => ActionAvailabilityMode::Conditional->value]);
    }

    public function customAvailability(): self
    {
        return $this->state(fn (): array => [
            'availability_mode' => ActionAvailabilityMode::Custom->value,
            'guard_class' => 'App\\Guards\\FakeActionGuard',
        ]);
    }

    public function requiresComment(): self
    {
        return $this->state(fn (): array => ['requires_comment' => true]);
    }
}
