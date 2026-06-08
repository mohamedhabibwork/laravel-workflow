# Troubleshooting

## `start()` Throws `InvalidWorkflowException`

Check that the workflow is active:

```php
$workflow->status->value === 'active';
```

Draft and archived workflows cannot start instances.

## Activation Fails

Activation requires:

- exactly one `start` step
- at least one `end` step
- workflow status `draft`

Run:

```bash
php artisan workflow:diagnose order-approval
```

## No Available Actions

Common causes:

- the current step authorization mode does not match the user
- the action guard condition returns false
- the action is custom and its handler/resolver returns false
- the instance is terminal
- the workflow is on a step with no actions

Call `currentStep()` first, then inspect the step's actions and assignees.

## `perform()` Throws `CommentRequiredException`

The action has `requires_comment = true`.

```php
$engine->perform($instance, 'reject', $user, [
    'comment' => 'Rejected because supporting evidence is missing.',
]);
```

## `perform()` Throws `TransitionNotFoundException`

No explicit action target or matching transition was found, and sequential fallback was not available. Add one of:

- `target_step_id` on the action
- a `WorkflowTransition` row
- set `require_explicit_transitions = false` and ensure step positions are ordered

## Attribute Compile Finds No Workflows

Check:

- `config('workflow.attribute_paths')`
- the file namespace and Composer autoload
- each workflow class has `#[AsWorkflow]`
- `php artisan optimize:clear`

Then run:

```bash
php artisan workflow:compile-attributes --path=app/Workflows --dry-run
```

## Tenancy Appears To Leak Rows

Check that:

- `workflow.tenancy.enabled` is true
- `workflow.tenancy.scope_provider` is bound and returns a non-null id
- admin commands are not using `--all`

When the provider returns `null`, the package intentionally applies no tenant constraint.

## Automation Stops At A Human Step

That is expected. Automation chains stop when they reach a `task`, `approval`, or `gateway` step that requires human action.

## Automation Fails

Inspect the `error` history event and the failed step instance data. After the underlying issue is fixed:

```php
$engine->retry($instance, auth()->user(), 'Retry after fixing the handler dependency.');
```

