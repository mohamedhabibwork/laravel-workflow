<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Engines\Authorizers;

use HFlow\LaravelWorkflow\Enums\AuthorizationMode;

/**
 * Registry that maps an {@see AuthorizationMode}
 * to the concrete {@see AuthorizerInterface} implementation that should be
 * used for a given step.
 *
 * The default engine ships with five implementations:
 *
 *   - public      → {@see PublicAuthorizer}
 *   - roles       → {@see RolesAuthorizer}
 *   - permissions → {@see PermissionsAuthorizer}
 *   - users       → {@see UsersAuthorizer}
 *   - custom      → {@see CustomAuthorizerDispatcher}
 *
 * Hosts may register additional modes by binding the FQCN of their custom
 * authorizer in the registry.
 */
final class AuthorizerRegistry
{
    /**
     * @var array<string, AuthorizerInterface>
     */
    private array $authorizers = [];

    public function register(AuthorizerInterface $authorizer): void
    {
        $this->authorizers[$authorizer->mode()->value] = $authorizer;
    }

    public function get(string $mode): AuthorizerInterface
    {
        return $this->authorizers[$mode] ?? $this->authorizers['public']
            ?? new PublicAuthorizer;
    }
}
