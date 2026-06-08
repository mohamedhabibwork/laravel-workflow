<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Commands;

use HFlow\LaravelWorkflow\Contracts\WorkflowEngine;
use HFlow\LaravelWorkflow\Models\WorkflowInstance;
use Illuminate\Console\Command;

/**
 * `workflow:history {instance} {--limit=20}` — Pretty-print the activity feed of an instance.
 */
final class WorkflowHistoryCommand extends Command
{
    protected $signature = 'workflow:history
        {instance : The UUID of the workflow instance}
        {--limit=20 : How many rows to show}';

    protected $description = 'Print the activity feed of a workflow instance as a table';

    public function handle(): int
    {
        $uuid = (string) $this->argument('instance');
        $instance = WorkflowInstance::query()->where('uuid', $uuid)->first();

        if ($instance === null) {
            $this->error("No workflow instance found with UUID [{$uuid}].");

            return self::FAILURE;
        }

        $limit = max(1, (int) $this->option('limit'));
        $maxPerPage = (int) config('workflow.commands.activity_feed.max_per_page', 100);
        $limit = min($limit, $maxPerPage);

        /** @var WorkflowEngine $engine */
        $engine = app(WorkflowEngine::class);

        $rows = $engine->history($instance->refresh(), $limit);

        if ($rows->isEmpty()) {
            $this->info("No history rows for instance [{$uuid}].");

            return self::SUCCESS;
        }

        $this->info("Activity feed for instance [{$uuid}] (last {$rows->count()} rows):");
        $this->newLine();

        $this->table(
            ['#', 'When', 'Event', 'Action', 'From Step', 'To Step', 'Actor', 'Comment'],
            $rows->map(fn ($h, $i): array => [
                (string) ($i + 1),
                $h->performed_at?->toIso8601String() ?? '-',
                $h->event->value,
                (string) ($h->action_code ?? '-'),
                (string) ($h->from_step_id ?? '-'),
                (string) ($h->to_step_id ?? '-'),
                (string) ($h->actor_id ?? 'system'),
                (string) ($h->comment ?? ''),
            ])->all(),
        );

        return self::SUCCESS;
    }
}
