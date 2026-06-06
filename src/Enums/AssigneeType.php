<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Enums;

enum AssigneeType: string
{
    case Role = 'role';
    case Permission = 'permission';
    case User = 'user';
    case Public = 'public';
    case Custom = 'custom';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
