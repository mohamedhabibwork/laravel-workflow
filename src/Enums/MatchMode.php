<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Enums;

enum MatchMode: string
{
    case Any = 'any';
    case All = 'all';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
