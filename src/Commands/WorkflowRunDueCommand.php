<?php

namespace HFlow\LaravelWorkflow\Commands;

use HFlow\LaravelWorkflow\Services\ActivityService;
use HFlow\LaravelWorkflow\Services\WorkflowEngine;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;

class WorkflowRunDueCommand extends Command
{
    public $signature = 'workflow:run-due';

    public $description = 'Process due workflow delayed starts, timers, and timeouts.';

    /**
     * Process due runtime work for Laravel scheduler integrations.
     */
    public function handle(WorkflowEngine $engine, ActivityService $activities): int
    {
        try {
            $started = $engine->processPendingStarts();
            $timers = $engine->fireDueTimers();
            $activityTasks = $activities->runDue();
            $timedOut = $engine->processTimeouts();
            $activityTimeouts = $activities->processTimeouts();
        } catch (QueryException) {
            $this->error('Workflow tables were not found. Run your package migrations first.');

            return self::FAILURE;
        }

        $this->info("Started {$started->count()} delayed workflow run(s).");
        $this->info("Fired {$timers->count()} workflow timer(s).");
        $this->info("Processed {$activityTasks->count()} workflow activit(y/ies).");
        $this->info("Timed out {$timedOut->count()} workflow run(s).");
        $this->info("Timed out {$activityTimeouts->count()} workflow activit(y/ies).");

        return self::SUCCESS;
    }
}
