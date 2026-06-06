<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Enums;

enum StepInstanceStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Completed = 'completed';
    case Skipped = 'skipped';
    case Returned = 'returned';
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
            self::Completed, self::Skipped, self::Returned, self::Rejected, self::Failed => true,
            default => false,
        };
    }
}
