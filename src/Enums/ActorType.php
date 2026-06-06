<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Enums;

final enum ActorType: string
{
    case User = 'user';
    case System = 'system';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
