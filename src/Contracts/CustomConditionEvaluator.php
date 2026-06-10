<?php

namespace HFlow\LaravelWorkflow\Contracts;

use HFlow\LaravelWorkflow\Models\WorkflowInstance;
use Illuminate\Contracts\Auth\Authenticatable as User;

interface CustomConditionEvaluator
{
    /**
     * Evaluate a custom condition against the instance, subject, context, and user.
     *
     * @param  array<string, mixed>  $context
     */
    public function evaluate(WorkflowInstance $instance, mixed $subject, array $context, ?User $user = null): bool;
}
