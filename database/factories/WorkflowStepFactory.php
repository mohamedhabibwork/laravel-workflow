<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Database\Factories;

use HFlow\LaravelWorkflow\Enums\AuthorizationMode;
use HFlow\LaravelWorkflow\Enums\StepType;
use HFlow\LaravelWorkflow\Models\Workflow;
use HFlow\LaravelWorkflow\Models\WorkflowStep;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkflowStep>
 */
final class WorkflowStepFactory extends Factory
{
    protected $model = WorkflowStep::class;

    public function definition(): array
    {
        return [
            'tenant_id' => null,
            'workflow_id' => Workflow::factory(),
            'name' => $this->faker->words(2, true),
            'code' => $this->faker->unique()->slug(2),
            'description' => $this->faker->sentence(),
            'type' => StepType::Task->value,
            'position' => $this->faker->numberBetween(1, 100),
            'authorization_mode' => AuthorizationMode::Roles->value,
            'match_mode' => 'all',
            'custom_authorizer' => null,
            'handler' => null,
            'is_skippable' => false,
            'is_returnable' => false,
            'sla_seconds' => null,
            'config' => null,
        ];
    }

    public function start(): self
    {
        return $this->state(fn (): array => ['type' => StepType::Start->value]);
    }

    public function end(): self
    {
        return $this->state(fn (): array => ['type' => StepType::End->value]);
    }

    public function approval(): self
    {
        return $this->state(fn (): array => ['type' => StepType::Approval->value]);
    }

    public function gateway(): self
    {
        return $this->state(fn (): array => ['type' => StepType::Gateway->value]);
    }
}
