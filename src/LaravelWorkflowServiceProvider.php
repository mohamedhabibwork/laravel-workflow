<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow;

use HFlow\LaravelWorkflow\Attributes\Compilation\AttributeCompiler;
use HFlow\LaravelWorkflow\Attributes\Compilation\AttributeCompilerContract;
use HFlow\LaravelWorkflow\Attributes\Discovery\AttributeWorkflowLoader;
use HFlow\LaravelWorkflow\Commands\CompileWorkflowAttributesCommand;
use HFlow\LaravelWorkflow\Commands\WorkflowDiagnoseCommand;
use HFlow\LaravelWorkflow\Commands\WorkflowHistoryCommand;
use HFlow\LaravelWorkflow\Commands\WorkflowInstanceStatusCommand;
use HFlow\LaravelWorkflow\Commands\WorkflowListCommand;
use HFlow\LaravelWorkflow\Contracts\TenantScopeProvider;
use HFlow\LaravelWorkflow\Contracts\WorkflowEngine;
use HFlow\LaravelWorkflow\Engines\ActivityFeed;
use HFlow\LaravelWorkflow\Engines\AssignmentMaterializer;
use HFlow\LaravelWorkflow\Engines\Authorizers\AuthorizerInterface;
use HFlow\LaravelWorkflow\Engines\Authorizers\AuthorizerRegistry;
use HFlow\LaravelWorkflow\Engines\Authorizers\CustomAuthorizerDispatcher;
use HFlow\LaravelWorkflow\Engines\Authorizers\PermissionsAuthorizer;
use HFlow\LaravelWorkflow\Engines\Authorizers\PublicAuthorizer;
use HFlow\LaravelWorkflow\Engines\Authorizers\RolesAuthorizer;
use HFlow\LaravelWorkflow\Engines\Authorizers\UsersAuthorizer;
use HFlow\LaravelWorkflow\Engines\AutomationRunner;
use HFlow\LaravelWorkflow\Engines\AvailableActionsResolver;
use HFlow\LaravelWorkflow\Engines\Conditions\ConditionEvaluator;
use HFlow\LaravelWorkflow\Engines\Conditions\ExpressionConditionEvaluator;
use HFlow\LaravelWorkflow\Engines\HandlerInvoker;
use HFlow\LaravelWorkflow\Engines\QuorumEvaluator;
use HFlow\LaravelWorkflow\Engines\TransitionResolver;
use HFlow\LaravelWorkflow\Engines\WorkflowEngine as WorkflowEngineImpl;
use HFlow\LaravelWorkflow\Models\WorkflowHistory;
use HFlow\LaravelWorkflow\Observability\HistoryRecorder;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Event;
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
            ->hasMigration('create_workflow_table')
            ->hasCommands([
                WorkflowListCommand::class,
                WorkflowInstanceStatusCommand::class,
                WorkflowHistoryCommand::class,
                WorkflowDiagnoseCommand::class,
                CompileWorkflowAttributesCommand::class,
            ]);
    }

    public function packageRegistered(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/workflow.php', 'workflow');

        $this->registerHostContracts();
        $this->registerCoreResolvers();
        $this->registerEngine();
        $this->registerObservabilityServices();
        $this->registerAutomationServices();
        $this->registerAttributeServices();
    }

    public function packageBooted(): void
    {
        $this->registerFactoryResolver();
        $this->registerHistoryListeners();
        $this->app->make(AttributeWorkflowLoader::class)->compileOnBoot();
    }

    /**
     * Register the Eloquent factory namespace resolver so workflow
     * models (HFlow\LaravelWorkflow\Models\*) can find their factories
     * in the package's published Database\Factories\ directory.
     *
     * Compatible with Laravel 10/11 (static Factory::guessFactoryNamesUsing)
     * and Laravel 12 (same static API).
     */
    protected function registerFactoryResolver(): void
    {
        if (! method_exists(Factory::class, 'guessFactoryNamesUsing')) {
            return;
        }

        Factory::guessFactoryNamesUsing(function (string $modelName): string {
            $basename = class_basename($modelName);
            $root = trim((string) config('workflow.factory_namespace', 'Database\\Factories'), '\\');
            $root = str_replace('/', '\\', $root);

            return $root.'\\'.$basename.'Factory';
        });
    }

    /**
     * Register the Phase 5 (US3) core resolvers:
     *   - AuthorizerInterface  → PublicAuthorizer (or host-supplied FQCN)
     *   - AuthorizerRegistry   → registered with all 5 stock authorizers
     *   - ConditionEvaluator   → ExpressionConditionEvaluator (always default)
     *
     * These are bound as singletons because they are stateless.
     */
    protected function registerCoreResolvers(): void
    {
        $authorizerContract = AuthorizerInterface::class;
        if (! $this->app->bound($authorizerContract) && interface_exists($authorizerContract)) {
            $configured = config('workflow.core.default_authorizer');
            $default = is_string($configured) && class_exists($configured)
                ? $configured
                : PublicAuthorizer::class;

            $this->app->singleton($authorizerContract, $default);
        }

        $registryContract = AuthorizerRegistry::class;
        if (! $this->app->bound($registryContract) && class_exists($registryContract)) {
            $this->app->singleton($registryContract, function (): AuthorizerRegistry {
                $registry = new AuthorizerRegistry;
                $registry->register(new PublicAuthorizer);
                $registry->register(new RolesAuthorizer);
                $registry->register(new PermissionsAuthorizer);
                $registry->register(new UsersAuthorizer);
                $registry->register(new CustomAuthorizerDispatcher);

                return $registry;
            });
        }

        $conditionContract = ConditionEvaluator::class;
        if (! $this->app->bound($conditionContract) && class_exists($conditionContract)) {
            $this->app->singleton(
                $conditionContract,
                fn ($app) => new ConditionEvaluator(
                    $app->make(ExpressionConditionEvaluator::class),
                ),
            );
        }
    }

    /**
     * Bind each host contract to either the configured FQCN or the default impl.
     * If the host did not provide a FQCN, we bind the default implementation as
     * a singleton so the engine can resolve it without further configuration.
     */
    protected function registerHostContracts(): void
    {
        // TenantScopeProvider is OPTIONAL — only bind if config provides a FQCN
        $tenantProvider = config('workflow.tenancy.scope_provider');
        if (is_string($tenantProvider) && $tenantProvider !== '') {
            $this->app->singleton(TenantScopeProvider::class, $tenantProvider);
        }
    }

    /**
     * Bind the WorkflowEngine interface to its default implementation.
     * The engine is a singleton because it is stateless after construction.
     *
     * Defensive: if any of the engine's dependencies don't exist yet, we
     * skip the binding. Tests that don't touch the engine will still pass.
     */
    protected function registerEngine(): void
    {
        $this->app->singleton(WorkflowEngine::class, function ($app): ?WorkflowEngine {
            if (! class_exists(WorkflowEngineImpl::class)) {
                return null;
            }

            $hasFullDeps = class_exists(AvailableActionsResolver::class)
                && class_exists(TransitionResolver::class)
                && class_exists(QuorumEvaluator::class)
                && class_exists(AssignmentMaterializer::class)
                && class_exists(HandlerInvoker::class)
                && class_exists(HistoryRecorder::class)
                && class_exists(AuthorizerRegistry::class)
                && class_exists(ConditionEvaluator::class)
                && class_exists(AutomationRunner::class)
                && class_exists(ActivityFeed::class);

            if (! $hasFullDeps) {
                return new WorkflowEngineImpl;
            }

            return new WorkflowEngineImpl(
                historyRecorder: $app->make(HistoryRecorder::class),
                actionsResolver: new AvailableActionsResolver(
                    $app->make(AuthorizerRegistry::class),
                    $app->make(ConditionEvaluator::class),
                ),
                transitionResolver: $app->make(TransitionResolver::class),
                quorumEvaluator: $app->make(QuorumEvaluator::class),
                assignmentMaterializer: $app->make(AssignmentMaterializer::class),
                handlerInvoker: $app->make(HandlerInvoker::class),
                conditionEvaluator: $app->make(ConditionEvaluator::class),
                automationRunner: $app->make(AutomationRunner::class),
                activityFeed: $app->make(ActivityFeed::class),
            );
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
            recorder: $app->make(HistoryRecorder::class),
            handlerInvoker: $app->make(HandlerInvoker::class),
            transitionResolver: $app->make(TransitionResolver::class),
        ));
    }

    protected function registerAttributeServices(): void
    {
        $this->app->singleton(AttributeWorkflowLoader::class, fn ($app) => new AttributeWorkflowLoader($app));
        $this->app->singleton(AttributeCompilerContract::class, fn ($app) => new AttributeCompiler(
            loader: $app->make(AttributeWorkflowLoader::class),
        ));
        $this->app->alias(AttributeCompilerContract::class, AttributeCompiler::class);
    }

    /**
     * Subscribe Laravel events to the history recorder.
     * When `workflow.events.fire_laravel_events` is false, the engine still
     * records history directly — but the host won't see the typed events.
     *
     * Defensive: only register listeners whose class files actually exist.
     * During incremental package bring-up (T015, T028, etc.) the listener
     * class may not exist yet; the engine will still record history but
     * no Laravel event will be dispatched.
     */
    protected function registerHistoryListeners(): void
    {
        if (! (bool) config('workflow.events.fire_laravel_events', true)) {
            return;
        }

        if (! class_exists(WorkflowHistory::class)) {
            return;
        }

        $listener = app(WorkflowHistory::class);

        $events = Arr::wrap(config('workflow.events.records') ?? []);

        foreach ($events as $eventClass) {
            if (! class_exists($eventClass)) {
                continue;
            }

            Event::listen($eventClass, [$listener, 'handle']);
        }
    }
}
