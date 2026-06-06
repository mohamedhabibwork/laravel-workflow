<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Observability;

use HFlow\LaravelWorkflow\Concerns\AppendOnlyHistory;
use HFlow\LaravelWorkflow\Enums\ActorType;
use HFlow\LaravelWorkflow\Enums\HistoryEvent;
use HFlow\LaravelWorkflow\Exceptions\AppendOnlyViolationException;
use HFlow\LaravelWorkflow\Models\WorkflowHistory;
use HFlow\LaravelWorkflow\Observability\Events\WorkflowHistoryRecorded;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The ONLY write path to `workflow_histories`.
 *
 * Direct INSERT via the query builder — Eloquent's `save()` is intentionally
 * NOT used so that {@see AppendOnlyHistory}
 * cannot prevent the insert via its update/delete guards.
 *
 * After the row is persisted, a {@see WorkflowHistoryRecorded} event is
 * dispatched so hosts can react (webhooks, audit, analytics, etc.).
 *
 * The recorder is deliberately framework-light: it only knows the schema of
 * `workflow_histories` and the set of {@see HistoryEvent} values.
 */
final class HistoryRecorder
{
    public function __construct(
        private readonly Dispatcher $events,
    ) {}

    /**
     * Record a single history row.
     *
     * @param  array{
     *     workflow_instance_id: int,
     *     step_instance_id?: int|null,
     *     from_step_id?: int|null,
     *     to_step_id?: int|null,
     *     action_code?: string|null,
     *     event: HistoryEvent|string,
     *     actor_id?: int|null,
     *     actor_type?: ActorType|string|null,
     *     comment?: string|null,
     *     metadata?: array<string, mixed>|null,
     *     performed_at?: \DateTimeInterface|string|null,
     *     tenant_id?: int|null,
     * }  $payload
     *
     * @throws QueryException
     * @throws AppendOnlyViolationException
     */
    public function record(array $payload): WorkflowHistory
    {
        $now = Carbon::now();
        $event = $payload['event'] instanceof HistoryEvent
            ? $payload['event']->value
            : (string) $payload['event'];
        $actorType = $payload['actor_type'] ?? ActorType::System;
        $actorTypeValue = $actorType instanceof ActorType
            ? $actorType->value
            : (string) $actorType;

        $row = [
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $payload['tenant_id'] ?? null,
            'workflow_instance_id' => $payload['workflow_instance_id'],
            'step_instance_id' => $payload['step_instance_id'] ?? null,
            'from_step_id' => $payload['from_step_id'] ?? null,
            'to_step_id' => $payload['to_step_id'] ?? null,
            'action_code' => $payload['action_code'] ?? null,
            'event' => $event,
            'actor_id' => $payload['actor_id'] ?? null,
            'actor_type' => $actorTypeValue,
            'comment' => $payload['comment'] ?? null,
            'metadata' => isset($payload['metadata'])
                ? json_encode($payload['metadata'])
                : null,
            'performed_at' => isset($payload['performed_at'])
                ? Carbon::parse($payload['performed_at'])
                : $now,
            'created_at' => $now,
        ];

        $id = DB::table((new WorkflowHistory)->getTable())->insertGetId($row);
        $row['id'] = $id;

        $history = (new WorkflowHistory)->newFromBuilder($row);

        $this->events->dispatch(new WorkflowHistoryRecorded($history, $now));

        return $history;
    }
}
