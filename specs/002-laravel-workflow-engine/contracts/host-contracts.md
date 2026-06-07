# Host-Supplied Contracts

**Feature**: 002-laravel-workflow-engine
**Date**: 2026-06-05
**Status**: Complete

The workflow engine is designed to be **fully host-driven** for any concern that depends on the host application. The package itself contains no opinionated authorization, no notification sender, and no-opinion scheduler; the host supplies all of those by implementing one of the six contracts in this document and registering it in the service container (or in a configuration array).

Every contract in this document is an **interface** in `src/Contracts/` with a single, well-defined method. The class name is stored on the corresponding row in the database (e.g. a `WorkflowStep` row's `custom_authorizer` column holds the FQCN of a class implementing `CustomAuthorizer`). At evaluation time, the engine resolves the FQCN through the host's class resolver and invokes the interface method.

---

## 1. `CustomAuthorizer`

**Where stored**: `workflow_steps.custom_authorizer` (when `authorization_mode = custom`)  
**Method**: `authorize(User $user, WorkflowInstance $instance, WorkflowStepInstance $stepInstance): bool`

```php
namespace App\Workflow\Authorizers;

use App\Models\User;
use HFlow\LaravelWorkflow\Contracts\CustomAuthorizer;
use HFlow\LaravelWorkflow\Models\WorkflowInstance;
use HFlow\LaravelWorkflow\Models\WorkflowStepInstance;

final class HighValueOrderAuthorizer implements CustomAuthorizer
{
    public function authorize(
        ?User $user,
        WorkflowInstance $instance,
        WorkflowStepInstance $stepInstance,
    ): bool {
        if (! $user) {
            return false;
        }

        $subject = $instance->workflowable; // morphTo
        if (! $subject instanceof \App\Models\Order) {
            return false;
        }

        return $user->can('approve-high-value')
            && $subject->amount > 10_000
            && $subject->region === $user->region;
    }
}
```

**Contract**:
- MUST return `true` if the user is eligible, `false` otherwise.
- MUST NOT mutate the instance or any related rows.
- MUST be safe to call repeatedly on the same inputs (the engine calls it twice — once for the read, once for the action).
- MUST NOT throw; return `false` on any internal error. (Logging is the host's choice.)

**Spec mapping**: US-1, US-3, BR-A-05, BR-X-07, FR-008.

---

## 2. `CustomConditionEvaluator`

**Where stored**: `workflow_conditions.evaluator` (when `kind = custom`)  
**Method**: `evaluate(array $context): bool`

The `$context` array has the following keys (all nullable):

| Key | Type | Description |
|---|---|---|
| `subject` | `Model` | The host model record bound to the instance (the `workflowable`). |
| `user` | `User\|null` | The current user (when the condition is evaluated in a user-driven context). |
| `instance` | `WorkflowInstance` | The current instance. |
| `step_instance` | `WorkflowStepInstance\|null` | The current step instance (when the condition is evaluated during step processing). |

```php
namespace App\Workflow\Conditions;

use HFlow\LaravelWorkflow\Contracts\CustomConditionEvaluator;

final class IsHighPriority implements CustomConditionEvaluator
{
    public function evaluate(array $context): bool
    {
        $subject = $context['subject'] ?? null;
        if (! $subject) {
            return false;
        }

        return method_exists($subject, 'priority')
            && $subject->priority === 'high';
    }
}
```

**Contract**:
- MUST return `true` to pass the guard, `false` to fail it.
- MUST NOT mutate the instance, the subject, or any related rows.
- MUST be pure with respect to its inputs: same input → same output.
- MUST NOT throw; return `false` on any internal error.

**Spec mapping**: US-1, US-3, BR-C-01, BR-X-09, FR-015.

---

## 3. `CustomActionHandler`

**Where stored**: `workflow_step_actions.handler` (when set)  
**Method**: `handle(WorkflowInstance $instance, WorkflowStepAction $action, array $payload): void`

| `$payload` key | Type | Description |
|---|---|---|
| `user` | `User\|null` | The actor performing the action. |
| `comment` | `string\|null` | The comment, if any. |
| `metadata` | `array` | Arbitrary host data the action was called with. |

```php
namespace App\Workflow\ActionHandlers;

use HFlow\LaravelWorkflow\Contracts\CustomActionHandler;
use HFlow\LaravelWorkflow\Models\WorkflowInstance;
use HFlow\LaravelWorkflow\Models\WorkflowStepAction;
use Illuminate\Support\Facades\Mail;
use App\Mail\OrderApprovedMail;

final class SendOrderApprovedMail implements CustomActionHandler
{
    public function handle(
        WorkflowInstance $instance,
        WorkflowStepAction $action,
        array $payload,
    ): void {
        $subject = $instance->workflowable;
        if (! $subject instanceof \App\Models\Order) {
            return;
        }

        Mail::to($subject->customer_email)
            ->send(new OrderApprovedMail($subject));
    }
}
```

**Contract**:
- The engine invokes the handler **after** eligibility and availability have been re-validated and **after** the leaving step has been closed.
- The handler MUST be **idempotent** with respect to its inputs. The engine does not guarantee exactly-once delivery; the host's handler must.
- The handler MAY throw; the engine catches the throwable, sets the leaving step to `failed` if it is an automated step (BR-X-22), or records an `error` history event and re-throws if it is a manual step. The error history event captures the exception class and message.
- The handler MUST NOT advance the instance or write to `workflow_step_instances` directly; that is the engine's job.
- The handler runs **synchronously** in the same PHP process as the action perform call. Async side effects are the host's job (the handler can dispatch a job).

**Spec mapping**: US-1, US-3, BR-AC-05, BR-X-15, FR-021.

---

## 4. `CustomStepHandler`

**Where stored**: `workflow_steps.handler` (when `step.type = automated`)  
**Method**: `handle(WorkflowInstance $instance, WorkflowStepInstance $stepInstance): array`

| Return | Type | Description |
|---|---|---|
| `array` | `array` | Step-local data to merge into `$stepInstance->data`. |

```php
namespace App\Workflow\StepHandlers;

use HFlow\LaravelWorkflow\Contracts\CustomStepHandler;
use HFlow\LaravelWorkflow\Models\WorkflowInstance;
use HFlow\LaravelWorkflow\Models\WorkflowStepInstance;
use Illuminate\Support\Facades\Http;

final class CallExternalEnrichmentApi implements CustomStepHandler
{
    public function handle(
        WorkflowInstance $instance,
        WorkflowStepInstance $stepInstance,
    ): array {
        $subject = $instance->workflowable;

        $response = Http::timeout(5)
            ->post('https://api.example.com/enrich', [
                'order_id' => $subject->id,
            ])
            ->throw();

        return [
            'enrichment' => $response->json('data'),
            'enriched_at' => now()->toIso8601String(),
        ];
    }
}
```

**Contract**:
- The engine invokes the handler **immediately on step entry** for `automated` steps (BR-X-21).
- The handler MUST return an array; the array is merged into the step instance's `data` column.
- The handler MAY throw; the engine catches the throwable, sets the step instance to `failed` and the instance to `failed` (BR-X-22), and records an `error` history event. The host can then `retry()` the instance.
- The handler MUST NOT call the engine API (no `engine->perform()`, no `engine->advance()`). It does its side effect and returns; the engine handles routing and history.
- The handler runs **synchronously**. Async side effects are the host's job.

**Spec mapping**: US-5, BR-X-21..23, FR-023.

---

## 5. `CustomResolver`

**Where stored**: `workflow_step_assignees.custom_resolver` (when `assignee_type = custom`)  
**Method**: `resolve(WorkflowInstance $instance, WorkflowStep $step): iterable<User>`

```php
namespace App\Workflow\Resolvers;

use App\Models\User;
use HFlow\LaravelWorkflow\Contracts\CustomResolver;
use HFlow\LaravelWorkflow\Models\WorkflowInstance;
use HFlow\LaravelWorkflow\Models\WorkflowStep;

final class ResolveByOrderRegion implements CustomResolver
{
    public function resolve(WorkflowInstance $instance, WorkflowStep $step): iterable
    {
        $subject = $instance->workflowable;
        if (! $subject instanceof \App\Models\Order) {
            return [];
        }

        return User::query()
            ->where('region', $subject->region)
            ->where('role', 'reviewer')
            ->cursor();
    }
}
```

**Contract**:
- The engine calls the resolver when it materializes runtime assignments for an approval/task step (BR-X-24).
- The resolver MUST return an `iterable` of host `User` instances (or an empty iterable).
- The resolver MUST NOT mutate the instance, the step, or any related rows.
- The resolver MUST be safe to call multiple times (the engine may call it on every available-actions query for cache freshness).
- The resolver MUST NOT throw; return an empty iterable on any internal error.

**Spec mapping**: US-1, US-3, BR-A-05, BR-X-24, FR-008.

---

## 6. `TenantScopeProvider`

**Where stored**: `config('workflow.tenant.scope_resolver')` (FQCN, optional)  
**Method**: `currentTenantId(): int|string|null`

```php
namespace App\Workflow\Tenancy;

use HFlow\LaravelWorkflow\Contracts\TenantScopeProvider;

final class CurrentTenantFromRequest implements TenantScopeProvider
{
    public function currentTenantId(): int|string|null
    {
        // e.g. from a header, from a session, from a domain, from a subdomain
        return request()->header('X-Tenant-Id')
            ?? session('current_tenant_id');
    }
}
```

**Contract**:
- The engine calls the resolver on every query that touches a tenancy-aware table, so the result must be cheap and side-effect-free.
- The resolver MUST return a `int|string|null` — the `tenant_id` value to scope by. Return `null` to mean "no tenant scope" (engine behaves as single-tenant).
- The resolver MUST NOT mutate any global state.
- The resolver MAY return `null` even when tenancy is enabled, in which case the engine performs a no-scope query and the host's authorization layer is responsible for safety. (Hosts that need strict per-tenant isolation should refuse `null` in their resolver.)

**Spec mapping**: US-7, BR-T-01..02, FR-029, FR-030.

---

## 7. Registration

The host registers a custom contract implementation in one of three ways:

1. **FQCN on the row** (for `CustomAuthorizer`, `CustomConditionEvaluator`, `CustomActionHandler`, `CustomStepHandler`, `CustomResolver`): the FQCN is stored in the corresponding `*_class` column. The engine resolves it through the host's class resolver (the Laravel container) at evaluation time.

2. **Service container binding** (alternative): the host can bind an interface to an implementation in a service provider:
   ```php
   $this->app->bind(\App\Workflow\Authorizers\HighValueOrderAuthorizer::class);
   ```
   The engine does the lookup by FQCN, so the binding just needs the class to be resolvable.

3. **Configuration** (for `TenantScopeProvider` only): the FQCN is set in `config/workflow.php`:
   ```php
   return [
       'tenant' => [
           'enabled' => true,
           'column' => 'tenant_id',
           'scope_resolver' => \App\Workflow\Tenancy\CurrentTenantFromRequest::class,
       ],
   ];
   ```

---

## 8. Versioning of the contracts

- Each contract interface is **stable** within a major version. Adding a method to an interface is a breaking change.
- New optional behavior is added via **separate interfaces** that hosts can opt into, not by extending the existing interfaces.
- Example: a future v2 `CustomAuthorizer` may declare a new interface `CustomAuthorizerWithReason` that adds a `reason()` method. Hosts that implement `CustomAuthorizerWithReason` get the reason in error messages; hosts that implement `CustomAuthorizer` get a generic error.
