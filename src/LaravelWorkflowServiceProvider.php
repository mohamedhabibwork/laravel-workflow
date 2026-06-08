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
use HFlow\LaravelWorkflow\Contracts\CustomActionHandler;
use HFlow\LaravelWorkflow\Contracts\CustomAuthorizer;
use HFlow\LaravelWorkflow\Contracts\CustomConditionEvaluator;
use HFlow\LaravelWorkflow\Contracts\CustomResolver;
use HFlow\LaravelWorkflow\Contracts\CustomStepHandler;
use HFlow\LaravelWorkflow\Contracts\TenantScopeProvider;
use HFlow\LaravelWorkflow\Contracts\WorkflowEngine;
use HFlow\LaravelWorkflow\Engines\Actions\ActionHandlerResolver;
use HFlow\LaravelWorkflow\Engines\Actions\DefaultActionHandler;
use HFlow\LaravelWorkflow\Engines\AssignmentMaterializer;
use HFlow\LaravelWorkflow\Engines\Authorizers\AuthorizerInterface;
use HFlow\LaravelWorkflow\Engines\Authorizers\AuthorizerRegistry;
use HFlow\LaravelWorkflow\Engines\Authorizers\AuthorizerResolver;
use HFlow\LaravelWorkflow\Engines\Authorizers\CustomAuthorizerDispatcher;
use HFlow\LaravelWorkflow\Engines\Authorizers\DefaultAuthorizer;
use HFlow\LaravelWorkflow\Engines\Authorizers\PermissionsAuthorizer;
use HFlow\LaravelWorkflow\Engines\Authorizers\PublicAuthorizer;
use HFlow\LaravelWorkflow\Engines\Authorizers\RolesAuthorizer;
use HFlow\LaravelWorkflow\Engines\Authorizers\UsersAuthorizer;
use HFlow\LaravelWorkflow\Engines\Automation\AutomationRunner;
use HFlow\LaravelWorkflow\Engines\AvailableActionsResolver;
use HFlow\LaravelWorkflow\Engines\Conditions\ConditionEvaluator;
use HFlow\LaravelWorkflow\Engines\Conditions\ConditionEvaluatorResolver;
use HFlow\LaravelWorkflow\Engines\Conditions\DefaultConditionEvaluator;
use HFlow\LaravelWorkflow\Engines\Conditions\ExpressionConditionEvaluator;
use HFlow\LaravelWorkflow\Engines\HandlerInvoker;
use HFlow\LaravelWorkflow\Engines\History\HistoryMaterializer;
use HFlow\LaravelWorkflow\Engines\History\QuorumEvaluator;
use HFlow\LaravelWorkflow\Engines\Resolvers\DefaultResolver;
use HFlow\LaravelWorkflow\Engines\Resolvers\ResolverRegistry;
use HFlow\LaravelWorkflow\Engines\Steps\DefaultStepHandler;
use HFlow\LaravelWorkflow\Engines\Steps\StepHandlerResolver;
use HFlow\LaravelWorkflow\Engines\TransitionResolver;
use HFlow\LaravelWorkflow\Engines\WorkflowEngine as WorkflowEngineImpl;
use HFlow\LaravelWorkflow\Events\ActionPerformed;
use HFlow\LaravelWorkflow\Events\AssignmentCompleted;
use HFlow\LaravelWorkflow\Events\AssignmentCreated;
use HFlow\LaravelWorkflow\Events\AssignmentExpired;
use HFlow\LaravelWorkflow\Events\InstanceCancelled;
use HFlow\LaravelWorkflow\Events\InstanceCompleted;
use HFlow\LaravelWorkflow\Events\InstanceFailed;
use HFlow\LaravelWorkflow\Events\InstanceHeld;
use HFlow\LaravelWorkflow\Events\InstanceResumed;
use HFlow\LaravelWorkflow\Events\InstanceStarted;
use HFlow\LaravelWorkflow\Events\StepCompleted;
use HFlow\LaravelWorkflow\Events\StepReturned;
use HFlow\LaravelWorkflow\Events\StepSkipped;
use HFlow\LaravelWorkflow\Events\StepStarted;
use HFlow\LaravelWorkflow\Listeners\RecordHistory;
use HFlow\LaravelWorkflow\Observability\ActivityFeed;
use HFlow\LaravelWorkflow\Observability\HistoryRecorder;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Event;
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
        if (! $this->app->bound($authorizerContract) && class_exists($authorizerContract)) {
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

        // Defensive: only bind the contract impl if it actually exists on disk.
        // During incremental bring-up, default impls may not exist yet.
        if (! class_exists($binding)) {
            return;
        }

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

        // Skip the resolver binding if the resolver class doesn't exist yet.
        if (! class_exists($resolverClass)) {
            return;
        }

        $this->app->singleton($resolverClass, fn ($app) => new $resolverClass($app->make($contract)));
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

            $hasFullDeps = class_exists(AuthorizerResolver::class)
                && class_exists(ConditionEvaluatorResolver::class)
                && class_exists(ActionHandlerResolver::class)
                && class_exists(StepHandlerResolver::class)
                && class_exists(ResolverRegistry::class)
                && class_exists(HistoryRecorder::class)
                && class_exists(HistoryMaterializer::class)
                && class_exists(QuorumEvaluator::class);

            if (! $hasFullDeps) {
                return new WorkflowEngineImpl;
            }

            return new WorkflowEngineImpl(
                historyRecorder: $app->make(HistoryRecorder::class),
                actionsResolver: class_exists(AvailableActionsResolver::class)
                    ? new AvailableActionsResolver(
                        $app->make(AuthorizerInterface::class),
                        $app->make(ConditionEvaluator::class),
                    )
                    : null,
                transitionResolver: class_exists(TransitionResolver::class)
                    ? $app->make(TransitionResolver::class)
                    : null,
                quorumEvaluator: class_exists(Engines\QuorumEvaluator::class)
                    ? $app->make(Engines\QuorumEvaluator::class)
                    : null,
                assignmentMaterializer: class_exists(AssignmentMaterializer::class)
                    ? $app->make(AssignmentMaterializer::class)
                    : null,
                handlerInvoker: class_exists(HandlerInvoker::class)
                    ? $app->make(HandlerInvoker::class)
                    : null,
            );
        });

        // Backward-compat alias: \HFlow\LaravelWorkflow\LaravelWorkflow resolves to the engine
        $this->app->singleton(LaravelWorkflow::class, function ($app): ?LaravelWorkflow {
            $engine = $app->make(WorkflowEngine::class);
            if ($engine === null) {
                return null;
            }

            return new LaravelWorkflow($engine);
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

        if (! class_exists(RecordHistory::class)) {
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
            if (! class_exists($eventClass)) {
                continue;
            }

            Event::listen($eventClass, [$listener, 'handle']);
        }
    }
}
