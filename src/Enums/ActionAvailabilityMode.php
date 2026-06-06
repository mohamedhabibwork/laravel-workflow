<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Enums;

final enum ActionAvailabilityMode: string
{
    case General = 'general';
    case Conditional = 'conditional';
    case Custom = 'custom';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
