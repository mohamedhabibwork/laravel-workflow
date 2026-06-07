<?php

declare(strict_types=1);

use HFlow\LaravelWorkflow\Engines\Authorizers\AuthorizerInterface;
use HFlow\LaravelWorkflow\Engines\Authorizers\CustomAuthorizerDispatcher;
use HFlow\LaravelWorkflow\Engines\Authorizers\PermissionsAuthorizer;
use HFlow\LaravelWorkflow\Engines\Authorizers\PublicAuthorizer;
use HFlow\LaravelWorkflow\Engines\Authorizers\RolesAuthorizer;
use HFlow\LaravelWorkflow\Engines\Authorizers\UsersAuthorizer;
use HFlow\LaravelWorkflow\Enums\AssigneeType;
use HFlow\LaravelWorkflow\Enums\AuthorizationMode;
use HFlow\LaravelWorkflow\Enums\StepType;
use HFlow\LaravelWorkflow\Models\Workflow;
use HFlow\LaravelWorkflow\Models\WorkflowInstance;
use HFlow\LaravelWorkflow\Models\WorkflowStep;
use HFlow\LaravelWorkflow\Models\WorkflowStepAssignee;
use HFlow\LaravelWorkflow\Models\WorkflowStepInstance;
use Illuminate\Support\Str;

/**
 * T048 — Unit tests for the 5 authorizer implementations.
 *
 * Each test creates a real step with assignees and exercises the
 * `authorize()` predicate.
 */
beforeEach(function (): void {
    $this->loadWorkflowMigrations();
    $this->workflow = new Workflow;
    $this->workflow->forceFill([
        'uuid' => (string) Str::uuid(),
        'code' => 'authorizer-unit-test',
        'name' => 'Authorizer Unit Test',
        'type' => 'generic',
        'version' => 1,
        'is_current_version' => true,
        'status' => 'active',
    ])->save();
});

/**
 * @return array{0: WorkflowStep, 1: WorkflowStepInstance, 2: WorkflowInstance}
 */
function makeAuthorizerFixtures(Workflow $workflow, AuthorizationMode $mode): array
{
    $step = new WorkflowStep;
    $step->forceFill([
        'uuid' => (string) Str::uuid(),
        'workflow_id' => $workflow->id,
        'name' => 'Test Step',
        'code' => 'test-step',
        'type' => StepType::Task->value,
        'position' => 0,
        'authorization_mode' => $mode->value,
        'match_mode' => 'all',
    ])->save();

    $instance = new WorkflowInstance;
    $instance->forceFill([
        'uuid' => (string) Str::uuid(),
        'workflow_id' => $workflow->id,
        'workflow_version' => 1,
        'subject_type' => 'host_unit',
        'subject_id' => 1,
        'status' => 'in_progress',
    ])->save();

    $stepInstance = new WorkflowStepInstance;
    $stepInstance->forceFill([
        'uuid' => (string) Str::uuid(),
        'workflow_instance_id' => $instance->id,
        'step_id' => $step->id,
        'status' => 'active',
    ])->save();

    return [$step, $stepInstance, $instance];
}

function addAssignee(WorkflowStep $step, string $type, ?string $value = null, ?string $customResolver = null): void
{
    $row = new WorkflowStepAssignee;
    $row->forceFill([
        'uuid' => (string) Str::uuid(),
        'step_id' => $step->id,
        'assignee_type' => $type,
        'assignee_value' => $value,
        'custom_resolver' => $customResolver,
    ])->save();
}

it('PublicAuthorizer always returns true regardless of user', function (): void {
    [$step, $stepInstance, $instance] = makeAuthorizerFixtures($this->workflow, AuthorizationMode::Public);
    $authorizer = new PublicAuthorizer;

    expect($authorizer->authorize(null, $instance, $stepInstance, $step))->toBeTrue()
        ->and($authorizer->authorize(new stdClass, $instance, $stepInstance, $step))->toBeTrue();
});

it('RolesAuthorizer returns false for a non-object user', function (): void {
    [$step, $stepInstance, $instance] = makeAuthorizerFixtures($this->workflow, AuthorizationMode::Roles);
    addAssignee($step, AssigneeType::Role->value, 'manager');

    $authorizer = new RolesAuthorizer;
    expect($authorizer->authorize(null, $instance, $stepInstance, $step))->toBeFalse()
        ->and($authorizer->authorize(42, $instance, $stepInstance, $step))->toBeFalse();
});

it('RolesAuthorizer returns true when user hasRole() returns true for a required role', function (): void {
    [$step, $stepInstance, $instance] = makeAuthorizerFixtures($this->workflow, AuthorizationMode::Roles);
    addAssignee($step, AssigneeType::Role->value, 'manager');
    addAssignee($step, AssigneeType::Role->value, 'admin');

    $user = new class
    {
        public function hasRole(string $role): bool
        {
            return $role === 'admin';
        }
    };

    expect((new RolesAuthorizer)->authorize($user, $instance, $stepInstance, $step))->toBeTrue();
});

it('RolesAuthorizer falls back to user_roles property when hasRole() is missing', function (): void {
    [$step, $stepInstance, $instance] = makeAuthorizerFixtures($this->workflow, AuthorizationMode::Roles);
    addAssignee($step, AssigneeType::Role->value, 'manager');

    $user = new class
    {
        public array $user_roles = ['editor', 'manager'];
    };

    expect((new RolesAuthorizer)->authorize($user, $instance, $stepInstance, $step))->toBeTrue();
});

it('RolesAuthorizer returns false when no roles match', function (): void {
    [$step, $stepInstance, $instance] = makeAuthorizerFixtures($this->workflow, AuthorizationMode::Roles);
    addAssignee($step, AssigneeType::Role->value, 'admin');

    $user = new class
    {
        public function hasRole(string $role): bool
        {
            return false;
        }
    };

    expect((new RolesAuthorizer)->authorize($user, $instance, $stepInstance, $step))->toBeFalse();
});

it('RolesAuthorizer returns false when step has no role assignees', function (): void {
    [$step, $stepInstance, $instance] = makeAuthorizerFixtures($this->workflow, AuthorizationMode::Roles);

    $user = new class
    {
        public function hasRole(string $role): bool
        {
            return true;
        }
    };

    expect((new RolesAuthorizer)->authorize($user, $instance, $stepInstance, $step))->toBeFalse();
});

it('PermissionsAuthorizer uses user->can() and returns true on first match', function (): void {
    [$step, $stepInstance, $instance] = makeAuthorizerFixtures($this->workflow, AuthorizationMode::Permissions);
    addAssignee($step, AssigneeType::Permission->value, 'approve-orders');
    addAssignee($step, AssigneeType::Permission->value, 'edit-orders');

    $user = new class
    {
        public function can(string $ability): bool
        {
            return $ability === 'edit-orders';
        }
    };

    expect((new PermissionsAuthorizer)->authorize($user, $instance, $stepInstance, $step))->toBeTrue();
});

it('PermissionsAuthorizer returns false when no permission matches', function (): void {
    [$step, $stepInstance, $instance] = makeAuthorizerFixtures($this->workflow, AuthorizationMode::Permissions);
    addAssignee($step, AssigneeType::Permission->value, 'approve-orders');

    $user = new class
    {
        public function can(string $ability): bool
        {
            return false;
        }
    };

    expect((new PermissionsAuthorizer)->authorize($user, $instance, $stepInstance, $step))->toBeFalse();
});

it('UsersAuthorizer returns true when user id is in the assignees list', function (): void {
    [$step, $stepInstance, $instance] = makeAuthorizerFixtures($this->workflow, AuthorizationMode::Users);
    addAssignee($step, AssigneeType::User->value, '42');
    addAssignee($step, AssigneeType::User->value, '100');

    $user = new class
    {
        public function getKey(): int
        {
            return 42;
        }
    };

    expect((new UsersAuthorizer)->authorize($user, $instance, $stepInstance, $step))->toBeTrue();
});

it('UsersAuthorizer accepts an integer user id directly', function (): void {
    [$step, $stepInstance, $instance] = makeAuthorizerFixtures($this->workflow, AuthorizationMode::Users);
    addAssignee($step, AssigneeType::User->value, '7');

    expect((new UsersAuthorizer)->authorize(7, $instance, $stepInstance, $step))->toBeTrue();
});

it('UsersAuthorizer returns false for null user or non-matching id', function (): void {
    [$step, $stepInstance, $instance] = makeAuthorizerFixtures($this->workflow, AuthorizationMode::Users);
    addAssignee($step, AssigneeType::User->value, '42');

    expect((new UsersAuthorizer)->authorize(null, $instance, $stepInstance, $step))->toBeFalse()
        ->and((new UsersAuthorizer)->authorize(99, $instance, $stepInstance, $step))->toBeFalse();
});

it('CustomAuthorizerDispatcher delegates to the FQCN on the step', function (): void {
    [$step, $stepInstance, $instance] = makeAuthorizerFixtures($this->workflow, AuthorizationMode::Custom);

    $step->custom_authorizer = CustomAuthorizerAlwaysYes::class;
    $step->save();

    expect((new CustomAuthorizerDispatcher)->authorize(null, $instance, $stepInstance, $step))->toBeTrue();
});

it('CustomAuthorizerDispatcher returns false for missing FQCN or non-existent class', function (): void {
    [$step, $stepInstance, $instance] = makeAuthorizerFixtures($this->workflow, AuthorizationMode::Custom);

    $step->custom_authorizer = null;
    $step->save();
    expect((new CustomAuthorizerDispatcher)->authorize(null, $instance, $stepInstance, $step))->toBeFalse();

    $step->custom_authorizer = 'HFlow\\LaravelWorkflow\\NonExistentClass';
    $step->save();
    expect((new CustomAuthorizerDispatcher)->authorize(null, $instance, $stepInstance, $step))->toBeFalse();
});

it('CustomAuthorizerDispatcher returns false when FQCN does not implement the contract', function (): void {
    [$step, $stepInstance, $instance] = makeAuthorizerFixtures($this->workflow, AuthorizationMode::Custom);

    $step->custom_authorizer = stdClass::class;
    $step->save();

    expect((new CustomAuthorizerDispatcher)->authorize(null, $instance, $stepInstance, $step))->toBeFalse();
});

/**
 * Test fixture for CustomAuthorizerDispatcher delegation.
 */
final class CustomAuthorizerAlwaysYes implements AuthorizerInterface
{
    public function mode(): AuthorizationMode
    {
        return AuthorizationMode::Custom;
    }

    public function authorize(
        mixed $user,
        WorkflowInstance $instance,
        WorkflowStepInstance $stepInstance,
        WorkflowStep $step,
    ): bool {
        return true;
    }
}
