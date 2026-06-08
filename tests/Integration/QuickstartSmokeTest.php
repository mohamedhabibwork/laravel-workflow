<?php

declare(strict_types=1);

use HFlow\LaravelWorkflow\Contracts\WorkflowEngine;
use HFlow\LaravelWorkflow\Enums\ActionAvailabilityMode;
use HFlow\LaravelWorkflow\Enums\ActionType;
use HFlow\LaravelWorkflow\Enums\AssigneeType;
use HFlow\LaravelWorkflow\Enums\AuthorizationMode;
use HFlow\LaravelWorkflow\Enums\HistoryEvent;
use HFlow\LaravelWorkflow\Enums\InstanceStatus;
use HFlow\LaravelWorkflow\Enums\MatchMode;
use HFlow\LaravelWorkflow\Enums\StepType;
use HFlow\LaravelWorkflow\Enums\TransitionType;
use HFlow\LaravelWorkflow\Enums\WorkflowStatus;
use HFlow\LaravelWorkflow\Enums\WorkflowType;
use HFlow\LaravelWorkflow\Models\Workflow;
use HFlow\LaravelWorkflow\Models\WorkflowHistory;
use HFlow\LaravelWorkflow\Models\WorkflowStep;
use HFlow\LaravelWorkflow\Models\WorkflowStepAction;
use HFlow\LaravelWorkflow\Models\WorkflowStepAssignee;
use HFlow\LaravelWorkflow\Models\WorkflowTransition;
use Illuminate\Support\Facades\Schema;
use Workbench\App\Models\Order;

beforeEach(function (): void {
    $this->loadWorkflowMigrations();

    Schema::create('host_orders_attributes', function ($table): void {
        $table->bigIncrements('id');
        $table->string('reference')->nullable();
        $table->timestamps();
    });
});

it('runs the quickstart workflow end to end in a fresh Testbench app', function (): void {
    /** @var WorkflowEngine $engine */
    $engine = app(WorkflowEngine::class);

    $workflow = Workflow::query()->create([
        'code' => 'quickstart-order-approval',
        'name' => 'Quickstart Order Approval',
        'description' => 'Two-step approval for high-value orders.',
        'type' => WorkflowType::Approval,
        'subject_type' => Order::class,
        'status' => WorkflowStatus::Draft,
        'version' => 1,
        'is_current_version' => false,
    ]);

    $submitted = WorkflowStep::query()->create([
        'workflow_id' => $workflow->getKey(),
        'code' => 'submitted',
        'name' => 'Submitted',
        'type' => StepType::Start,
        'position' => 1,
        'authorization_mode' => AuthorizationMode::Public,
        'match_mode' => MatchMode::Any,
    ]);

    $review = WorkflowStep::query()->create([
        'workflow_id' => $workflow->getKey(),
        'code' => 'manager-review',
        'name' => 'Manager Review',
        'type' => StepType::Approval,
        'position' => 2,
        'authorization_mode' => AuthorizationMode::Roles,
        'match_mode' => MatchMode::Any,
        'is_returnable' => true,
        'sla_seconds' => 86400,
    ]);

    $approved = WorkflowStep::query()->create([
        'workflow_id' => $workflow->getKey(),
        'code' => 'approved',
        'name' => 'Approved',
        'type' => StepType::End,
        'position' => 3,
        'authorization_mode' => AuthorizationMode::Public,
        'match_mode' => MatchMode::Any,
    ]);

    $workflow->update(['start_step_id' => $submitted->getKey()]);

    WorkflowStepAssignee::query()->create([
        'step_id' => $review->getKey(),
        'assignee_type' => AssigneeType::Role,
        'assignee_value' => 'manager',
    ]);

    WorkflowStepAction::query()->create([
        'step_id' => $submitted->getKey(),
        'code' => 'submit',
        'name' => 'Submit',
        'type' => ActionType::Submit,
        'availability_mode' => ActionAvailabilityMode::General,
        'target_step_id' => $review->getKey(),
        'sort_order' => 0,
    ]);

    WorkflowStepAction::query()->create([
        'step_id' => $review->getKey(),
        'code' => 'approve',
        'name' => 'Approve',
        'type' => ActionType::Approve,
        'availability_mode' => ActionAvailabilityMode::General,
        'target_step_id' => $approved->getKey(),
        'requires_comment' => false,
        'sort_order' => 1,
    ]);

    WorkflowStepAction::query()->create([
        'step_id' => $review->getKey(),
        'code' => 'reject',
        'name' => 'Reject',
        'type' => ActionType::Reject,
        'availability_mode' => ActionAvailabilityMode::General,
        'target_step_id' => $approved->getKey(),
        'requires_comment' => true,
        'sort_order' => 2,
    ]);

    WorkflowTransition::query()->create([
        'workflow_id' => $workflow->getKey(),
        'from_step_id' => $submitted->getKey(),
        'to_step_id' => $review->getKey(),
        'type' => TransitionType::Forward,
    ]);

    WorkflowTransition::query()->create([
        'workflow_id' => $workflow->getKey(),
        'from_step_id' => $review->getKey(),
        'to_step_id' => $approved->getKey(),
        'type' => TransitionType::Forward,
    ]);

    $active = $engine->activate($workflow);
    $order = Order::query()->create(['reference' => 'QS-001']);

    $instance = $engine->start($active, $order, ['requester_role' => 'sales']);
    $current = $engine->currentStep($instance);

    expect($instance->workflowable->is($order))->toBeTrue()
        ->and($current->step->code)->toBe('submitted')
        ->and($engine->availableActions($instance)->keys())->toBe(['submit']);

    $instance = $engine->perform($instance, 'submit');

    $manager = new class
    {
        public int $id = 101;

        public function hasRole(string $role): bool
        {
            return $role === 'manager';
        }
    };

    expect($engine->currentStep($instance)->step->code)->toBe('manager-review')
        ->and($engine->availableActions($instance, $manager)->keys())->toBe(['approve', 'reject']);

    $completed = $engine->perform($instance, 'approve', $manager, ['comment' => 'Looks good.']);

    $events = WorkflowHistory::query()
        ->where('workflow_instance_id', $completed->getKey())
        ->orderBy('id')
        ->pluck('event')
        ->map(fn (HistoryEvent|string $event): string => $event instanceof HistoryEvent ? $event->value : $event)
        ->all();

    expect($completed->status)->toBe(InstanceStatus::Completed)
        ->and($events)->toContain(HistoryEvent::Started->value)
        ->and($events)->toContain(HistoryEvent::StepEntered->value)
        ->and($events)->toContain(HistoryEvent::StepCompleted->value)
        ->and($events)->toContain(HistoryEvent::ActionPerformed->value)
        ->and($events)->toContain(HistoryEvent::Completed->value);
});
