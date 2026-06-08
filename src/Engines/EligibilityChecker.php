<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Engines;

use HFlow\LaravelWorkflow\Engines\Authorizers\AuthorizerRegistry;
use HFlow\LaravelWorkflow\Engines\Authorizers\CustomAuthorizerDispatcher;
use HFlow\LaravelWorkflow\Engines\Authorizers\PermissionsAuthorizer;
use HFlow\LaravelWorkflow\Engines\Authorizers\PublicAuthorizer;
use HFlow\LaravelWorkflow\Engines\Authorizers\RolesAuthorizer;
use HFlow\LaravelWorkflow\Engines\Authorizers\UsersAuthorizer;
use HFlow\LaravelWorkflow\Models\WorkflowInstance;
use HFlow\LaravelWorkflow\Models\WorkflowStepInstance;

final class EligibilityChecker
{
    public function __construct(
        private readonly ?AuthorizerRegistry $authorizers = null,
    ) {}

    public function isEligible(mixed $user, WorkflowInstance $instance, WorkflowStepInstance $stepInstance): bool
    {
        $step = $stepInstance->step;
        if ($step === null) {
            return false;
        }

        $mode = $step->authorization_mode->value;

        return $this->registry()->get($mode)->authorize($user, $instance, $stepInstance, $step);
    }

    private function registry(): AuthorizerRegistry
    {
        if ($this->authorizers instanceof AuthorizerRegistry) {
            return $this->authorizers;
        }

        if (app()->bound(AuthorizerRegistry::class)) {
            return app(AuthorizerRegistry::class);
        }

        $registry = new AuthorizerRegistry;
        $registry->register(new PublicAuthorizer);
        $registry->register(new RolesAuthorizer);
        $registry->register(new PermissionsAuthorizer);
        $registry->register(new UsersAuthorizer);
        $registry->register(new CustomAuthorizerDispatcher);

        return $registry;
    }
}
