# Actions and Conditions

Actions are configured on workflow steps and determine what a user can do while a step is active.

## Action Fields

Common fields:

- `name`: Human-readable name.
- `code`: Stable action key, such as `approve`.
- `type`: `ActionType` enum.
- `availability_mode`: `AvailabilityMode` enum.
- `guard_condition_id`: Optional condition for conditional availability.
- `guard_class`: Optional custom guard class.
- `target_step_id`: Optional explicit next step.
- `requires_comment`: Requires `payload['comment']`.
- `handler`: Optional action side-effect handler class.

## Requires Comment

```php
$approval->actions()->create([
    'name' => 'Reject',
    'code' => 'reject',
    'type' => ActionType::Reject,
    'requires_comment' => true,
]);
```

If the action is performed without a comment, the engine throws an exception.

## Authorization Modes

Steps use `AuthorizationMode`:

- `public`: Anyone can act.
- `users`: Explicit user IDs in step assignees.
- `roles`: User must expose `hasAnyRole(array $roles)`.
- `permissions`: User must expose `hasAnyPermission(array $permissions)`.
- `custom`: Uses a custom authorizer class.

## Conditions

Conditions can be expression-based, custom, or composite.

```php
$condition = $workflow->conditions()->create([
    'name' => 'Large order',
    'code' => 'large-order',
    'kind' => ConditionKind::Expression,
    'expression' => [
        'logic' => 'and',
        'clauses' => [
            ['field' => 'amount', 'operator' => 'gt', 'value' => 1000],
        ],
    ],
]);
```

Supported expression operators:

- `eq`, `==`
- `neq`, `!=`
- `gt`, `>`
- `gte`, `>=`
- `lt`, `<`
- `lte`, `<=`
- `in`
- `contains`

## Conditional Action

```php
$approval->actions()->create([
    'name' => 'Approve Large Order',
    'code' => 'approve-large',
    'type' => ActionType::Approve,
    'availability_mode' => AvailabilityMode::Conditional,
    'guard_condition_id' => $condition->id,
]);
```
