<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Enums;

final enum StepType: string
{
    case Start = 'start';
    case Task = 'task';
    case Approval = 'approval';
    case Automated = 'automated';
    case Gateway = 'gateway';
    case End = 'end';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
