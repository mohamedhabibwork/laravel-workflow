<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Enums;

final enum AuthorizationMode: string
{
    case Public = 'public';
    case Roles = 'roles';
    case Permissions = 'permissions';
    case Users = 'users';
    case Custom = 'custom';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
