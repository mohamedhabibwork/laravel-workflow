<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Enums;

final enum HistoryEvent: string
{
    case Started = 'started';
    case StepEntered = 'step_entered';
    case StepCompleted = 'step_completed';
    case ActionPerformed = 'action_performed';
    case Skipped = 'skipped';
    case Returned = 'returned';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case CommentAdded = 'comment_added';
    case Error = 'error';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
