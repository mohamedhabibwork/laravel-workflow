<?php

namespace HFlow\LaravelWorkflow\Contracts;

use HFlow\LaravelWorkflow\Models\WorkflowInstance;
use Illuminate\Contracts\Auth\Authenticatable as User;

interface WorkflowQueryHandler
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(WorkflowInstance $instance, string $query, array $payload = [], ?User $user = null): mixed;
}
