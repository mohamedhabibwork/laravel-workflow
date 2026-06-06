<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Enums;

final enum InstanceStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case OnHold = 'on_hold';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Rejected = 'rejected';
    case Failed = 'failed';

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
            self::Completed, self::Cancelled, self::Rejected => true,
            default => false,
        };
    }
}
