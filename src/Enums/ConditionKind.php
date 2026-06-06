<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Enums;

enum ConditionKind: string
{
    case Expression = 'expression';
    case Custom = 'custom';
    case Composite = 'composite';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
