<?php

namespace HFlow\LaravelWorkflow;

use HFlow\LaravelWorkflow\Commands\WorkflowCheckCommand;
use HFlow\LaravelWorkflow\Commands\WorkflowListCommand;
use HFlow\LaravelWorkflow\Commands\WorkflowRunDueCommand;
use HFlow\LaravelWorkflow\Commands\WorkflowSyncAttributesCommand;
use HFlow\LaravelWorkflow\Commands\WorkflowWorkerCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class LaravelWorkflowServiceProvider extends PackageServiceProvider
{
    /**
     * Configure the package name, publishable assets, migration, and commands.
     */
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('laravel-workflow')
            ->hasConfigFile('workflow')
            ->hasMigration('create_workflow_table')
            ->hasCommands([
                WorkflowListCommand::class,
                WorkflowCheckCommand::class,
                WorkflowSyncAttributesCommand::class,
                WorkflowRunDueCommand::class,
                WorkflowWorkerCommand::class,
            ]);
    }

    /**
     * Register workflow services and the public facade target as singletons.
     */
    public function packageRegistered(): void
    {
        $this->app->singleton(Services\WorkflowEngine::class, function ($app) {
            $class = $this->configuredClass('workflow_engine', Services\WorkflowEngine::class);
            $parameters = [
                'actionResolver' => $app->make(Services\ActionResolver::class),
                'conditionEvaluator' => $app->make(Services\ConditionEvaluator::class),
                'container' => $app,
            ];

            return $class === Services\WorkflowEngine::class
                ? new Services\WorkflowEngine(...$parameters)
                : $app->makeWith($class, $parameters);
        });

        $this->app->singleton(Services\WorkflowService::class, function ($app) {
            $class = $this->configuredClass('workflow_service', Services\WorkflowService::class);

            return $class === Services\WorkflowService::class
                ? new Services\WorkflowService
                : $app->make($class);
        });

        $this->app->singleton(Services\AttributeWorkflowRegistrar::class, function ($app) {
            $class = $this->configuredClass('attribute_workflow_registrar', Services\AttributeWorkflowRegistrar::class);
            $parameters = [
                'workflowService' => $app->make(Services\WorkflowService::class),
            ];

            return $class === Services\AttributeWorkflowRegistrar::class
                ? new Services\AttributeWorkflowRegistrar(...$parameters)
                : $app->makeWith($class, $parameters);
        });

        $this->app->singleton(Services\ActivityService::class, function ($app) {
            $class = $this->configuredClass('activity_service', Services\ActivityService::class);
            $parameters = [
                'container' => $app,
            ];

            return $class === Services\ActivityService::class
                ? new Services\ActivityService(...$parameters)
                : $app->makeWith($class, $parameters);
        });

        $this->app->singleton(Services\ActionResolver::class, function ($app) {
            $class = $this->configuredClass('action_resolver', Services\ActionResolver::class);
            $parameters = [
                'conditionEvaluator' => $app->make(Services\ConditionEvaluator::class),
                'container' => $app,
            ];

            return $class === Services\ActionResolver::class
                ? new Services\ActionResolver(...$parameters)
                : $app->makeWith($class, $parameters);
        });

        $this->app->singleton(Services\ConditionEvaluator::class, function ($app) {
            $class = $this->configuredClass('condition_evaluator', Services\ConditionEvaluator::class);
            $parameters = [
                'container' => $app,
            ];

            return $class === Services\ConditionEvaluator::class
                ? new Services\ConditionEvaluator(...$parameters)
                : $app->makeWith($class, $parameters);
        });

        $this->app->singleton(LaravelWorkflow::class, function ($app) {
            $class = $this->configuredClass('api', LaravelWorkflow::class);
            $parameters = [
                'engine' => $app->make(Services\WorkflowEngine::class),
                'service' => $app->make(Services\WorkflowService::class),
                'actionResolver' => $app->make(Services\ActionResolver::class),
                'activityService' => $app->make(Services\ActivityService::class),
                'attributeRegistrar' => $app->make(Services\AttributeWorkflowRegistrar::class),
            ];

            return $class === LaravelWorkflow::class
                ? new LaravelWorkflow(...$parameters)
                : $app->makeWith($class, $parameters);
        });
    }

    /**
     * Resolve a configured class override for a package component.
     *
     * @param  class-string  $default
     * @return class-string
     */
    protected function configuredClass(string $key, string $default): string
    {
        $class = config("workflow.classes.{$key}", $default);

        if (! is_string($class) || ! class_exists($class)) {
            return $default;
        }

        if ($class !== $default && ! is_subclass_of($class, $default)) {
            return $default;
        }

        return $class;
    }
}
