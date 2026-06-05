<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow;

use HFlow\LaravelWorkflow\Contracts\CustomActionHandler;
use HFlow\LaravelWorkflow\Contracts\CustomAuthorizer;
use HFlow\LaravelWorkflow\Contracts\CustomConditionEvaluator;
use HFlow\LaravelWorkflow\Contracts\CustomResolver;
use HFlow\LaravelWorkflow\Contracts\CustomStepHandler;
use HFlow\LaravelWorkflow\Contracts\TenantScopeProvider;
use HFlow\LaravelWorkflow\Contracts\WorkflowEngine;
use HFlow\LaravelWorkflow\Engines\WorkflowEngine as WorkflowEngineImpl;
use HFlow\LaravelWorkflow\Events\InstanceStarted;
use HFlow\LaravelWorkflow\Events\InstanceCompleted;
use HFlow\LaravelWorkflow\Events\InstanceCancelled;
use HFlow\LaravelWorkflow\Events\InstanceFailed;
use HFlow\LaravelWorkflow\Events\InstanceHeld;
use HFlow\LaravelWorkflow\Events\InstanceResumed;
use HFlow\LaravelWorkflow\Events\StepStarted;
use HFlow\LaravelWorkflow\Events\StepCompleted;
use HFlow\LaravelWorkflow\Events\StepSkipped;
use HFlow\LaravelWorkflow\Events\StepReturned;
use HFlow\LaravelWorkflow\Events\ActionPerformed;
use HFlow\LaravelWorkflow\Events\AssignmentCreated;
use HFlow\LaravelWorkflow\Events\AssignmentCompleted;
use HFlow\LaravelWorkflow\Events\AssignmentExpired;
use HFlow\LaravelWorkflow\Listeners\RecordHistory;
use HFlow\LaravelWorkflow\Observability\HistoryRecorder;
use HFlow\LaravelWorkflow\Observability\ActivityFeed;
use HFlow\LaravelWorkflow\Engines\Authorizers\DefaultAuthorizer;
use HFlow\LaravelWorkflow\Engines\Authorizers\AuthorizerResolver;
use HFlow\LaravelWorkflow\Engines\Conditions\DefaultConditionEvaluator;
use HFlow\LaravelWorkflow\Engines\Conditions\ConditionEvaluatorResolver;
use HFlow\LaravelWorkflow\Engines\Actions\DefaultActionHandler;
use HFlow\LaravelWorkflow\Engines\Actions\ActionHandlerResolver;
use HFlow\LaravelWorkflow\Engines\Steps\DefaultStepHandler;
use HFlow\LaravelWorkflow\Engines\Steps\StepHandlerResolver;
use HFlow\LaravelWorkflow\Engines\Resolvers\DefaultResolver;
use HFlow\LaravelWorkflow\Engines\Resolvers\ResolverRegistry;
use HFlow\LaravelWorkflow\Engines\Automation\AutomationRunner;
use HFlow\LaravelWorkflow\Engines\History\HistoryMaterializer;
use HFlow\LaravelWorkflow\Engines\History\QuorumEvaluator;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

/**
 * Service provider for the laravel-workflow package.
 *
 * Responsibilities:
 *  - Register the package config file (workflow.php)
 *  - Publish & load the combined workflow migration
 *  - Bind the 6 host contracts + 1 tenant contract based on config
 *  - Bind the WorkflowEngine interface to the default implementation
 *  - Register history/activity/automation services as singletons
 *  - Subscribe to Laravel events when `workflow.events.fire_laravel_events` is true
 */
class LaravelWorkflowServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-workflow')
            ->hasConfigFile()
            ->hasMigration('create_workflow_table');
    }

    public function packageRegistered(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/workflow.php', 'workflow');

        $this->registerHostContracts();
        $this->registerEngine();
        $this->registerObservabilityServices();
        $this->registerAutomationServices();
    }

    public function packageBooted(): void
    {
        $this->registerHistoryListeners();
    }

    /**
     * Bind each host contract to either the configured FQCN or the default impl.
     * If the host did not provide a FQCN, we bind the default implementation as
     * a singleton so the engine can resolve it without further configuration.
     */
    protected function registerHostContracts(): void
    {
        $this->bindContract(
            CustomAuthorizer::class,
            'workflow.custom_contracts.authorizer',
            DefaultAuthorizer::class,
        );

        $this->bindContract(
            CustomConditionEvaluator::class,
            'workflow.custom_contracts.condition_evaluator',
            DefaultConditionEvaluator::class,
        );

        $this->bindContract(
            CustomActionHandler::class,
            'workflow.custom_contracts.action_handler',
            DefaultActionHandler::class,
        );

        $this->bindContract(
            CustomStepHandler::class,
            'workflow.custom_contracts.step_handler',
            DefaultStepHandler::class,
        );

        $this->bindContract(
            CustomResolver::class,
            'workflow.custom_contracts.resolver',
            DefaultResolver::class,
        );

        // TenantScopeProvider is OPTIONAL — only bind if config provides a FQCN
        $tenantProvider = config('workflow.tenancy.scope_provider');
        if (is_string($tenantProvider) && $tenantProvider !== '') {
            $this->app->singleton(TenantScopeProvider::class, $tenantProvider);
        }
    }

    /**
     * Bind a host contract to either a configured FQCN or a default implementation.
     */
    protected function bindContract(string $contract, string $configKey, string $defaultImpl): void
    {
        $configured = config($configKey);
        $binding = (is_string($configured) && $configured !== '') ? $configured : $defaultImpl;

        $this->app->singleton($contract, $binding);

        // Register the resolver wrapper that delegates to the bound contract
        $resolverClass = match ($contract) {
            CustomAuthorizer::class => AuthorizerResolver::class,
            CustomConditionEvaluator::class => ConditionEvaluatorResolver::class,
            CustomActionHandler::class => ActionHandlerResolver::class,
            CustomStepHandler::class => StepHandlerResolver::class,
            CustomResolver::class => ResolverRegistry::class,
            default => throw new InvalidArgumentException("Unknown host contract: {$contract}"),
        };

        $this->app->singleton($resolverClass, fn ($app) => new $resolverClass($app->make($contract)));
    }

    /**
     * Bind the WorkflowEngine interface to its default implementation.
     * The engine is a singleton because it is stateless after construction.
     */
    protected function registerEngine(): void
    {
        $this->app->singleton(WorkflowEngine::class, function ($app): WorkflowEngine {
            return new WorkflowEngineImpl(
                authorizer: $app->make(AuthorizerResolver::class),
                conditions: $app->make(ConditionEvaluatorResolver::class),
                actions: $app->make(ActionHandlerResolver::class),
                steps: $app->make(StepHandlerResolver::class),
                resolvers: $app->make(ResolverRegistry::class),
                history: $app->make(HistoryRecorder::class),
                materializer: $app->make(HistoryMaterializer::class),
                quorum: $app->make(QuorumEvaluator::class),
                events: $app->make(Dispatcher::class),
            );
        });

        // Backward-compat alias: \HFlow\LaravelWorkflow\LaravelWorkflow resolves to the engine
        $this->app->singleton(\HFlow\LaravelWorkflow\LaravelWorkflow::class, function ($app): \HFlow\LaravelWorkflow\LaravelWorkflow {
            return new \HFlow\LaravelWorkflow\LaravelWorkflow($app->make(WorkflowEngine::class));
        });
    }

    /**
     * Register the history recorder and activity feed as singletons.
     * They are stateless after construction (no per-instance data) so
     * reusing them across requests is safe.
     */
    protected function registerObservabilityServices(): void
    {
        $this->app->singleton(HistoryRecorder::class, fn ($app) => new HistoryRecorder(
            events: $app->make(Dispatcher::class),
        ));

        $this->app->singleton(ActivityFeed::class, fn ($app) => new ActivityFeed(
            defaultPerPage: (int) config('workflow.commands.activity_feed.default_per_page', 25),
            maxPerPage: (int) config('workflow.commands.activity_feed.max_per_page', 100),
        ));
    }

    /**
     * Register automation pipeline services.
     */
    protected function registerAutomationServices(): void
    {
        $this->app->singleton(AutomationRunner::class, fn ($app) => new AutomationRunner(
            maxRetries: (int) config('workflow.automation.max_retry_attempts', 3),
            backoffSeconds: (array) config('workflow.automation.retry_backoff_seconds', [10, 60, 300]),
        ));
    }

    /**
     * Subscribe Laravel events to the history recorder.
     * When `workflow.events.fire_laravel_events` is false, the engine still
     * records history directly — but the host won't see the typed events.
     */
    protected function registerHistoryListeners(): void
    {
        if (! (bool) config('workflow.events.fire_laravel_events', true)) {
            return;
        }

        $listener = app(RecordHistory::class);

        $events = [
            InstanceStarted::class,
            InstanceCompleted::class,
            InstanceCancelled::class,
            InstanceFailed::class,
            InstanceHeld::class,
            InstanceResumed::class,
            StepStarted::class,
            StepCompleted::class,
            StepSkipped::class,
            StepReturned::class,
            ActionPerformed::class,
            AssignmentCreated::class,
            AssignmentCompleted::class,
            AssignmentExpired::class,
        ];

        foreach ($events as $eventClass) {
            Event::listen($eventClass, [$listener, 'handle']);
        }
    }
}
