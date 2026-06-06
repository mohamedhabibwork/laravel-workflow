<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Enums;

final enum AssignmentStatus: string
{
    case Pending = 'pending';
    case Acted = 'acted';
    case Reassigned = 'reassigned';
    case Expired = 'expired';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Acted, self::Reassigned, self::Expired => true,
            default => false,
        };
    }
}
