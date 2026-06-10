<?php

use HFlow\LaravelWorkflow\Enums\WorkflowStatus;
use HFlow\LaravelWorkflow\Models\Workflow;
use HFlow\LaravelWorkflow\Models\WorkflowStep;
use HFlow\LaravelWorkflow\Services\WorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = new WorkflowService;
});

test('creating a new version increments the version number and sets status to draft', function () {
    $workflow = Workflow::factory()->active()->create(['version' => 1]);

    $newVersion = $this->service->createNewVersion($workflow);

    expect($newVersion->version)->toBe(2);
    expect($newVersion->status)->toBe(WorkflowStatus::Draft);
    expect($newVersion->is_current_version)->toBeFalse();
});

test('activating a new version makes it current and deactivates old version', function () {
    $v1 = Workflow::factory()->active()->create(['code' => 'test-wf', 'version' => 1]);

    $v2 = $this->service->createNewVersion($v1);

    // Setup v2 steps for activation
    WorkflowStep::factory()->start()->create(['workflow_id' => $v2->id]);
    WorkflowStep::factory()->end()->create(['workflow_id' => $v2->id]);

    $this->service->activate($v2);

    expect($v2->fresh()->is_current_version)->toBeTrue();
    expect($v1->fresh()->is_current_version)->toBeFalse();
});
