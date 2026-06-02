<?php

namespace HFlow\LaravelWorkflow\Commands;

use Illuminate\Console\Command;

class LaravelWorkflowCommand extends Command
{
    public $signature = 'laravel-workflow';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
