<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Database\Factories;

use HFlow\LaravelWorkflow\Enums\ActorType;
use HFlow\LaravelWorkflow\Enums\HistoryEvent;
use HFlow\LaravelWorkflow\Models\WorkflowHistory;
use HFlow\LaravelWorkflow\Models\WorkflowInstance;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkflowHistory>
 */
final class WorkflowHistoryFactory extends Factory
{
    protected $model = WorkflowHistory::class;

    public function definition(): array
    {
        return [
            'workflow_instance_id' => WorkflowInstance::factory(),
            'step_instance_id' => null,
            'from_step_id' => null,
            'to_step_id' => null,
            'action_code' => null,
            'event' => HistoryEvent::Started->value,
            'actor_id' => null,
            'actor_type' => ActorType::System->value,
            'comment' => null,
            'metadata' => null,
            'performed_at' => now(),
            'created_at' => now(),
        ];
    }

    public function actionPerformed(string $actionCode = 'approve', ?int $actorId = null): self
    {
        return $this->state(fn (): array => [
            'event' => HistoryEvent::ActionPerformed->value,
            'action_code' => $actionCode,
            'actor_id' => $actorId,
            'actor_type' => $actorId === null ? ActorType::System->value : ActorType::User->value,
        ]);
    }

    public function comment(string $comment): self
    {
        return $this->state(fn (): array => ['comment' => $comment]);
    }
}
