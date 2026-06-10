<?php

namespace HFlow\LaravelWorkflow\Contracts;

use HFlow\LaravelWorkflow\Models\WorkflowInstance;
use Illuminate\Contracts\Auth\Authenticatable as User;

interface WorkflowSignalHandler
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(WorkflowInstance $instance, string $signal, array $payload = [], ?User $user = null): void;
}
