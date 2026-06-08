# Authorization And Conditions

## Authorization Modes

| Mode | How it is checked |
|---|---|
| `public` | Anyone, including system/no user, may act. |
| `roles` | User must match a role assignee. The stock authorizer supports `hasRole()` and fallback role properties. |
| `permissions` | User must pass `can()` for a permission assignee. |
| `users` | User id must match a user assignee. |
| `custom` | The step resolves `custom_authorizer` and calls `CustomAuthorizer::authorize()`. |

## Assignee Types

| Type | Value |
|---|---|
| `role` | Role name. |
| `permission` | Permission name. |
| `user` | User id as a string. |
| `public` | Public assignment marker. |
| `custom` | Uses `custom_resolver`. |

## Action Availability

| Mode | Behavior |
|---|---|
| `general` | Always available when the user is eligible for the step. |
| `conditional` | Evaluates the linked guard condition. |
| `custom` | Resolves `guard_class` / custom handler and treats falsy as unavailable. |

## Expression Conditions

Expression conditions evaluate structured arrays:

```php
[
    'op' => 'and',
    'clauses' => [
        ['field' => 'subject.amount', 'operator' => 'gt', 'value' => 1000],
        ['field' => 'context.channel', 'operator' => 'eq', 'value' => 'web'],
    ],
    'groups' => [
        [
            'op' => 'or',
            'clauses' => [
                ['field' => 'user.id', 'operator' => 'in', 'value' => [1, 2, 3]],
                ['field' => 'subject.priority', 'operator' => 'eq', 'value' => 'high'],
            ],
        ],
    ],
]
```

Supported field roots:

- `subject.*`
- `context.*`
- `user.*`
- `instance.*`

Supported operators:

- `eq`
- `neq`
- `gt`
- `gte`
- `lt`
- `lte`
- `in`
- `not_in`
- `contains`
- `starts_with`
- `ends_with`
- `is_null`
- `is_not_null`
- `is_true`

Safety caps:

- maximum recursion depth: 10
- maximum clause count: 100

## Host Contracts

Custom behavior lives behind interfaces:

- `CustomAuthorizer`
- `CustomConditionEvaluator`
- `CustomActionHandler`
- `CustomStepHandler`
- `CustomResolver`
- `TenantScopeProvider`

Implementations should be side-effect-free unless they are handlers. Authorizers, condition evaluators, and resolvers should return false/empty values instead of throwing.

