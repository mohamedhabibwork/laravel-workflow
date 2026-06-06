<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Enums;

enum WorkflowType: string
{
    case Automation = 'automation';
    case Approval = 'approval';
    case Generic = 'generic';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
