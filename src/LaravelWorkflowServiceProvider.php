<?php

namespace HFlow\LaravelWorkflow;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use HFlow\LaravelWorkflow\Commands\LaravelWorkflowCommand;

class LaravelWorkflowServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('laravel-workflow')
            ->hasConfigFile()
            ->hasViews()
            ->hasMigration('create_laravel_workflow_table')
            ->hasCommand(LaravelWorkflowCommand::class);
    }
}
