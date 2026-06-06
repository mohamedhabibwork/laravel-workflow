<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Observability\Events;

use HFlow\LaravelWorkflow\Models\WorkflowHistory;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched AFTER a row has been written to `workflow_histories`.
 *
 * The event carries the freshly-recorded {@see WorkflowHistory} row and the
 * timestamp at which the row was persisted. Hosts subscribe via standard
 * Laravel event listeners — the package itself does not register any listener
 * for this event.
 */
final readonly class WorkflowHistoryRecorded
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public WorkflowHistory $history,
        public \DateTimeInterface $recordedAt,
    ) {}
}
