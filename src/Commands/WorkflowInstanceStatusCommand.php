<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Commands;

use HFlow\LaravelWorkflow\Contracts\WorkflowEngine;
use HFlow\LaravelWorkflow\Models\WorkflowInstance;
use Illuminate\Console\Command;

/**
 * `workflow:status {instance?}` — Show the current step + recent history of an instance.
 *
 * If no instance uuid is provided, the command prompts for one. The instance
 * is shown with: uuid, workflow code + version, current step, status, started
 * at, completed at, and a 5-row tail of the history feed.
 */
final class WorkflowInstanceStatusCommand extends Command
{
    protected $signature = 'workflow:status
        {instance? : The UUID of the workflow instance}
        {--tail=5 : How many recent history rows to show}';

    protected $description = 'Show the current step + recent history of a workflow instance';

    public function handle(): int
    {
        $uuid = (string) ($this->argument('instance') ?? '');
        if ($uuid === '') {
            $uuid = trim((string) $this->ask('Workflow instance UUID'));
        }
        if ($uuid === '') {
            $this->error('Instance UUID is required.');

            return self::FAILURE;
        }

        $instance = WorkflowInstance::query()->where('uuid', $uuid)->first();
        if ($instance === null) {
            $this->error("No workflow instance found with UUID [{$uuid}].");

            return self::FAILURE;
        }

        /** @var WorkflowEngine $engine */
        $engine = app(WorkflowEngine::class);

        $this->line(sprintf(
            '  <info>Instance</info>     %s',
            $instance->uuid,
        ));
        $this->line(sprintf(
            '  <info>Workflow</info>     %s (v%d)',
            $instance->workflow?->code ?? '-',
            $instance->workflow_version,
        ));
        $this->line(sprintf('  <info>Status</info>       %s', $instance->status->value));
        $this->line(sprintf('  <info>Current Step</info> %s', $instance->currentStep()?->step?->name ?? '-'));
        $this->line(sprintf('  <info>Started</info>      %s', $instance->started_at?->toIso8601String() ?? '-'));
        $this->line(sprintf('  <info>Completed</info>    %s', $instance->completed_at?->toIso8601String() ?? '-'));
        $this->newLine();

        $tail = max(1, (int) $this->option('tail'));
        $history = $engine->history($instance->refresh(), $tail);

        if ($history->isEmpty()) {
            $this->warn('  No history rows yet.');

            return self::SUCCESS;
        }

        $this->table(
            ['When', 'Event', 'Action', 'Actor', 'Comment'],
            $history->map(fn ($h): array => [
                $h->performed_at?->toIso8601String() ?? '-',
                $h->event->value,
                (string) ($h->action_code ?? '-'),
                (string) ($h->actor_id ?? 'system'),
                (string) ($h->comment ?? ''),
            ])->all(),
        );

        return self::SUCCESS;
    }
}
