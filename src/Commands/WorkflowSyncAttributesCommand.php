<?php

namespace HFlow\LaravelWorkflow\Commands;

use HFlow\LaravelWorkflow\Services\AttributeWorkflowRegistrar;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Throwable;

class WorkflowSyncAttributesCommand extends Command
{
    public $signature = 'workflow:sync-attributes
        {class? : Specific attributed workflow class to sync}
        {--activate : Activate workflows after syncing}';

    public $description = 'Sync PHP attribute workflow definitions into database workflow models.';

    /**
     * Sync configured or explicitly provided attribute workflow classes.
     */
    public function handle(AttributeWorkflowRegistrar $registrar): int
    {
        try {
            $class = $this->argument('class');

            if (is_string($class) && $class !== '') {
                $workflow = $registrar->sync($class, (bool) $this->option('activate'));
                $this->info("Synced workflow '{$workflow->code}' version {$workflow->version}.");

                return self::SUCCESS;
            }

            $workflows = $registrar->syncConfigured((bool) $this->option('activate'));
        } catch (QueryException) {
            $this->error('Workflow tables were not found. Run your package migrations first.');

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($workflows === []) {
            $this->info('No attribute workflows are configured.');

            return self::SUCCESS;
        }

        foreach ($workflows as $workflow) {
            $this->info("Synced workflow '{$workflow->code}' version {$workflow->version}.");
        }

        return self::SUCCESS;
    }
}
