<?php

namespace HFlow\LaravelWorkflow\Commands;

use HFlow\LaravelWorkflow\Services\ActivityService;
use HFlow\LaravelWorkflow\Services\WorkflowEngine;
use Illuminate\Console\Command;

class WorkflowWorkerCommand extends Command
{
    public $signature = 'workflow:work
        {--queue= : Activity task queue to process}
        {--limit=50 : Maximum activities per loop}
        {--sleep=1 : Seconds to sleep between loops}
        {--once : Process one loop and stop}';

    public $description = 'Run a Laravel workflow worker loop for due starts, timers, timeouts, and activities.';

    /**
     * Run the worker process.
     */
    public function handle(WorkflowEngine $engine, ActivityService $activities): int
    {
        do {
            $started = $engine->processPendingStarts();
            $timers = $engine->fireDueTimers();
            $activityTasks = $activities->runDue(
                $this->option('queue') ?: null,
                (int) $this->option('limit')
            );
            $workflowTimeouts = $engine->processTimeouts();
            $activityTimeouts = $activities->processTimeouts();

            $this->line(
                "started={$started->count()} timers={$timers->count()} activities={$activityTasks->count()} workflow_timeouts={$workflowTimeouts->count()} activity_timeouts={$activityTimeouts->count()}"
            );

            if ($this->option('once')) {
                break;
            }

            sleep((int) $this->option('sleep'));
        } while (true);

        return self::SUCCESS;
    }
}
