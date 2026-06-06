<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Enums;

final enum TransitionType: string
{
    case Forward = 'forward';
    case Skip = 'skip';
    case Return = 'return';
    case Conditional = 'conditional';
    case Automatic = 'automatic';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
