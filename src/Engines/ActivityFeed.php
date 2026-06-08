<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Engines;

use HFlow\LaravelWorkflow\Concerns\AppendOnlyHistory;
use HFlow\LaravelWorkflow\Models\WorkflowHistory;
use HFlow\LaravelWorkflow\Models\WorkflowInstance;
use HFlow\LaravelWorkflow\Observability\HistoryRecorder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Read-only view of `workflow_histories` for a given instance.
 *
 * The activity feed is the user-facing surface of {@see WorkflowHistory}.
 * It always eager-loads {@see WorkflowHistory::fromStep()} and
 * {@see WorkflowHistory::toStep()} so the host can render labels without
 * N+1 queries, and returns a live Eloquent collection (no caching)
 * so the host sees events that have just been recorded.
 *
 * Ordering contract:
 *   - default (no `$limit`): chronological asc (oldest first).
 *   - with `$limit`:        most-recent first (desc).
 *
 * The append-only nature of `workflow_histories` is guaranteed upstream by
 * {@see AppendOnlyHistory} and the
 * direct-INSERT path in {@see HistoryRecorder};
 * the feed never modifies rows.
 */
final class ActivityFeed
{
    public function __construct(
        private readonly int $defaultPerPage = 25,
        private readonly int $maxPerPage = 100,
    ) {}

    /**
     * Read the activity feed for an instance.
     *
     * @return Collection<int, WorkflowHistory>
     */
    public function read(
        WorkflowInstance $instance,
        ?int $limit = null,
        ?string $event = null,
    ): Collection {
        $query = WorkflowHistory::query()
            ->where('workflow_instance_id', $instance->getKey())
            ->with(['fromStep', 'toStep']);

        if ($event !== null) {
            $query->where('event', $event);
        }

        if ($limit !== null) {
            $query
                ->orderByDesc('performed_at')
                ->orderByDesc('id')
                ->limit($limit);
        } else {
            $query
                ->orderBy('performed_at')
                ->orderBy('id');
        }

        return $query->get();
    }
}
