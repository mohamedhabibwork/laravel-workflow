<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Enums;

enum WorkflowStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Archived = 'archived';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
