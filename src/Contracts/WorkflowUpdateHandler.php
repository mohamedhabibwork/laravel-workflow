<?php

namespace HFlow\LaravelWorkflow\Contracts;

use HFlow\LaravelWorkflow\Models\WorkflowInstance;
use Illuminate\Contracts\Auth\Authenticatable as User;

interface WorkflowUpdateHandler
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function handle(WorkflowInstance $instance, string $update, array $payload = [], ?User $user = null): array;
}
