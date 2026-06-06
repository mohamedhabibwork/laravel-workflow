<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Database\Factories;

use HFlow\LaravelWorkflow\Enums\InstanceStatus;
use HFlow\LaravelWorkflow\Models\Workflow;
use HFlow\LaravelWorkflow\Models\WorkflowInstance;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkflowInstance>
 */
final class WorkflowInstanceFactory extends Factory
{
    protected $model = WorkflowInstance::class;

    public function definition(): array
    {
        return [
            'tenant_id' => null,
            'workflow_id' => Workflow::factory(),
            'workflow_version' => 1,
            'subject_type' => 'App\\Models\\Order',
            'subject_id' => (string) $this->faker->numberBetween(1, 10000),
            'current_step_id' => null,
            'status' => InstanceStatus::InProgress->value,
            'context' => ['order_total' => 100.0],
            'initiated_by' => null,
            'started_at' => now(),
            'completed_at' => null,
        ];
    }

    public function completed(): self
    {
        return $this->state(fn (): array => [
            'status' => InstanceStatus::Completed->value,
            'completed_at' => now(),
        ]);
    }

    public function cancelled(): self
    {
        return $this->state(fn (): array => [
            'status' => InstanceStatus::Cancelled->value,
            'completed_at' => now(),
        ]);
    }
}
