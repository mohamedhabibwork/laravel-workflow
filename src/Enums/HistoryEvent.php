<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Enums;

use HFlow\LaravelWorkflow\Tests\Unit\HistoryPayloadTest;

enum HistoryEvent: string
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

    /**
     * The list of well-known metadata keys recorded for this event.
     *
     * Documented here so {@see HistoryPayloadTest}
     * can assert that every event has a non-empty schema, and so consumers
     * can discover which keys to read from a history row.
     *
     * Values are always scalars (string|int|bool|float) or arrays of
     * scalars — never raw Eloquent models.
     *
     * @return array<int, string>
     */
    public function metadataSchema(): array
    {
        return match ($this) {
            self::Started => ['workflow_code', 'workflow_version'],
            self::StepEntered => ['step_type'],
            self::StepCompleted => ['status'],
            self::ActionPerformed => ['resolved_action_code'],
            self::Skipped => ['reason'],
            self::Returned => ['reason'],
            self::Completed => ['final_status'],
            self::Cancelled => ['reason'],
            self::CommentAdded => ['step_instance_id'],
            self::Error => ['exception', 'message'],
        };
    }
}
