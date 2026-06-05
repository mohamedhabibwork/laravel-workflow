<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Enums;

final enum WorkflowType: string
{
    case Automation = 'automation';
    case Approval = 'approval';
    case Generic = 'generic';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
