<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Database\Factories;

use HFlow\LaravelWorkflow\Enums\WorkflowStatus;
use HFlow\LaravelWorkflow\Enums\WorkflowType;
use HFlow\LaravelWorkflow\Models\Workflow;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Workflow>
 */
final class WorkflowFactory extends Factory
{
    protected $model = Workflow::class;

    public function definition(): array
    {
        $code = 'wf_'.$this->faker->unique()->slug(3);

        return [
            'tenant_id' => null,
            'name' => ucwords(str_replace('_', ' ', $code)),
            'code' => $code,
            'description' => $this->faker->sentence(),
            'type' => WorkflowType::Generic->value,
            'subject_type' => null,
            'version' => 1,
            'is_current_version' => true,
            'status' => WorkflowStatus::Draft->value,
            'start_step_id' => null,
            'require_explicit_transitions' => false,
            'config' => null,
        ];
    }

    public function active(): self
    {
        return $this->state(fn (): array => ['status' => WorkflowStatus::Active->value]);
    }

    public function archived(): self
    {
        return $this->state(fn (): array => ['status' => WorkflowStatus::Archived->value]);
    }

    public function automation(): self
    {
        return $this->state(fn (): array => ['type' => WorkflowType::Automation->value]);
    }

    public function approval(): self
    {
        return $this->state(fn (): array => ['type' => WorkflowType::Approval->value]);
    }
}
