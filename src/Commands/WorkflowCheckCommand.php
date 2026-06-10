<?php

namespace HFlow\LaravelWorkflow\Commands;

use HFlow\LaravelWorkflow\Models\Workflow;
use HFlow\LaravelWorkflow\Services\WorkflowService;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Throwable;

class WorkflowCheckCommand extends Command
{
    public $signature = 'workflow:check {code : Workflow code to validate}';

    public $description = 'Validate a workflow definition can be activated.';

    /**
     * Validate that the current version of the requested workflow can activate.
     */
    public function handle(): int
    {
        try {
            $workflow = Workflow::query()
                ->where('code', $this->argument('code'))
                ->where('is_current_version', true)
                ->first();
        } catch (QueryException) {
            $this->error('Workflow tables were not found. Run your package migrations first.');

            return self::FAILURE;
        }

        if (! $workflow) {
            $this->error("Workflow '{$this->argument('code')}' was not found.");

            return self::FAILURE;
        }

        try {
            $workflowService = app(WorkflowService::class);
            $workflowService->validateActivation($workflow);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Workflow '{$workflow->code}' is valid for activation.");

        return self::SUCCESS;
    }
}
