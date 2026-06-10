# Contracts: Laravel Workflow Package

## Overview

The engine provides several extension points (contracts) that host applications must implement to provide custom logic for authorization, condition evaluation, and action handling.

## 1. CustomAuthorizer
Invoked when a step's `authorization_mode` is set to `custom`.

```php
interface CustomAuthorizer
{
    /**
     * Determine if the user is eligible for the current step.
     */
    public function authorize(User $user, WorkflowInstance $instance, WorkflowStepInstance $stepInstance): bool;
}
```

## 2. ConditionEvaluator
Invoked when a `WorkflowCondition`'s `kind` is set to `custom`.

```php
interface ConditionEvaluator
{
    /**
     * Evaluate the custom condition.
     */
    public function evaluate(WorkflowInstance $instance, mixed $subject, array $context, ?User $user = null): bool;
}
```

## 3. StepHandler
Invoked when an `automated` step is entered.

```php
interface StepHandler
{
    /**
     * Execute the automated logic for the step.
     * Should return an array of data to be stored in the step instance context.
     */
    public function handle(WorkflowStepInstance $stepInstance, array $context): array;
}
```

## 4. ActionHandler
Invoked as a side-effect when a `WorkflowStepAction` is performed.

```php
interface ActionHandler
{
    /**
     * Handle the side-effect of an action.
     */
    public function handle(WorkflowStepInstance $stepInstance, string $actionCode, array $payload): void;
}
```

## 5. AssigneeResolver
Used to resolve explicit users for `custom` assignee types.

```php
interface AssigneeResolver
{
    /**
     * Resolve the list of eligible user IDs for a step.
     * @return array<int>
     */
    public function resolve(WorkflowStep $step, WorkflowInstance $instance): array;
}
```
