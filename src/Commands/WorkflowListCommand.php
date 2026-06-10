<?php

namespace HFlow\LaravelWorkflow\Commands;

use HFlow\LaravelWorkflow\Models\Workflow;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;

class WorkflowListCommand extends Command
{
    public $signature = 'workflow:list {--status= : Filter workflows by status}';

    public $description = 'List workflow definitions.';

    /**
     * Render the workflow definition list for the configured tenant/database.
     */
    public function handle(): int
    {
        try {
            $workflows = Workflow::query()
                ->when($this->option('status'), fn ($query, string $status) => $query->where('status', $status))
                ->orderBy('code')
                ->orderByDesc('version')
                ->get(['id', 'name', 'code', 'version', 'status', 'is_current_version']);
        } catch (QueryException) {
            $this->error('Workflow tables were not found. Run your package migrations first.');

            return self::FAILURE;
        }

        if ($workflows->isEmpty()) {
            $this->info('No workflows found.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Name', 'Code', 'Version', 'Status', 'Current'],
            $workflows->map(fn (Workflow $workflow) => [
                $workflow->id,
                $workflow->name,
                $workflow->code,
                $workflow->version,
                $workflow->status->value,
                $workflow->is_current_version ? 'yes' : 'no',
            ])->all()
        );

        return self::SUCCESS;
    }
}
