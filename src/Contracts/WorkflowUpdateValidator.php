<?php

namespace HFlow\LaravelWorkflow\Contracts;

use HFlow\LaravelWorkflow\Models\WorkflowInstance;
use Illuminate\Contracts\Auth\Authenticatable as User;

interface WorkflowUpdateValidator
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function validate(WorkflowInstance $instance, string $update, array $payload = [], ?User $user = null): bool;
}
