<?php

namespace HFlow\LaravelWorkflow\Contracts;

use HFlow\LaravelWorkflow\Models\WorkflowInstance;
use HFlow\LaravelWorkflow\Models\WorkflowStepInstance;
use Illuminate\Contracts\Auth\Authenticatable as User;

interface CustomAuthorizer
{
    /**
     * Determine whether the user can act on the current workflow step instance.
     */
    public function authorize(User $user, WorkflowInstance $instance, WorkflowStepInstance $stepInstance): bool;
}
