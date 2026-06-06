<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Tests;

use HFlow\LaravelWorkflow\Contracts\TenantScopeProvider;
use HFlow\LaravelWorkflow\Enums\AssigneeType;
use HFlow\LaravelWorkflow\Enums\AuthorizationMode;
use HFlow\LaravelWorkflow\Enums\InstanceStatus;
use HFlow\LaravelWorkflow\Enums\StepType;
use HFlow\LaravelWorkflow\Enums\WorkflowStatus;
use HFlow\LaravelWorkflow\Enums\WorkflowType;
use HFlow\LaravelWorkflow\Facades\LaravelWorkflow;
use HFlow\LaravelWorkflow\LaravelWorkflowServiceProvider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Base TestCase for the laravel-workflow package.
 *
 * Wires the service provider, factory guessing, and a clean SQLite-in-memory
 * database. Migration loading is OPT-IN: tests that need the workflow tables
 * call `loadWorkflowMigrations()` in their setUp. Tests that don't (e.g.
 * ArchTest, value-object unit tests) skip it entirely.
 *
 * Provides ergonomic helpers for common test scenarios (creating a workflow,
 * starting an instance, asserting history rows, etc.).
 */
class TestCase extends Orchestra
{
    /**
     * Whether a fake host `users` table was created during the test.
     * Some test helpers add this; we use it to make the helper idempotent.
     */
    protected bool $withHostUsersTable = false;

    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'HFlow\\LaravelWorkflow\\Database\\Factories\\'.class_basename($modelName).'Factory',
        );
    }

    /**
     * Run the single combined workflow migration against the in-memory test DB.
     * Tests that need a migrated database should call this from their setUp()
     * or from a `beforeEach()` hook in their Pest file.
     */
    protected function loadWorkflowMigrations(): void
    {
        $migration = __DIR__.'/../database/migrations/2024_01_01_000000_create_workflow_table.php';

        if (! file_exists($migration)) {
            $this->markTestSkipped('Combined workflow migration not found. Run T006 first.');
        }

        (include $migration)->up();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function getEnvironmentSetUp($app): array
    {
        $config = [
            'database.default' => 'testing',
            'workflow.table_prefix' => 'workflow_',
            'workflow.history_retention_days' => null,
            'workflow.tenancy.enabled' => false,
            'workflow.tenancy.column' => 'tenant_id',
            'workflow.tenancy.scope_provider' => null,
            'workflow.custom_contracts.authorizer' => null,
            'workflow.custom_contracts.condition_evaluator' => null,
            'workflow.custom_contracts.action_handler' => null,
            'workflow.custom_contracts.step_handler' => null,
            'workflow.custom_contracts.resolver' => null,
            'workflow.events.fire_laravel_events' => true,
            'workflow.history.on_dispatch_failure' => 'skip',
            'workflow.automation.max_retry_attempts' => 3,
            'workflow.automation.retry_backoff_seconds' => [10, 60, 300],
        ];

        foreach ($config as $key => $value) {
            config()->set($key, $value);
        }

        return $config;
    }

    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            LaravelWorkflowServiceProvider::class,
        ];
    }

    /**
     * @return array<int, class-string>
     */
    protected function getPackageAliases($app): array
    {
        return [
            'LaravelWorkflow' => LaravelWorkflow::class,
        ];
    }

    /**
     * Create a minimal `users` table for tests that need to reference assignees.
     * Idempotent — safe to call multiple times in the same test.
     */
    protected function createHostUsersTable(): void
    {
        if ($this->withHostUsersTable || Schema::hasTable('users')) {
            $this->withHostUsersTable = true;

            return;
        }

        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->timestamps();
        });

        $this->withHostUsersTable = true;
    }

    /**
     * Get the workflow table prefix for the current test configuration.
     */
    protected function tablePrefix(): string
    {
        return (string) config('workflow.table_prefix', 'workflow_');
    }

    /**
     * Resolve the fully-qualified table name for a logical workflow table.
     */
    protected function table(string $name): string
    {
        return $this->tablePrefix().$name;
    }

    /**
     * Common workflow-type value for test fixtures.
     */
    protected function approvalWorkflowType(): WorkflowType
    {
        return WorkflowType::Approval;
    }

    protected function activeWorkflowStatus(): WorkflowStatus
    {
        return WorkflowStatus::Active;
    }

    protected function startStepType(): StepType
    {
        return StepType::Start;
    }

    protected function userAssigneeType(): AssigneeType
    {
        return AssigneeType::User;
    }

    protected function anyAuthorizationMode(): AuthorizationMode
    {
        return AuthorizationMode::Any;
    }

    protected function runningInstanceStatus(): InstanceStatus
    {
        return InstanceStatus::Running;
    }

    /**
     * Bind a fake TenantScopeProvider that always returns the given tenant id.
     */
    protected function bindTenantContext(?string $tenantId): void
    {
        config()->set('workflow.tenancy.enabled', $tenantId !== null);

        $this->app->instance(
            TenantScopeProvider::class,
            new class($tenantId) implements TenantScopeProvider
            {
                public function __construct(private readonly ?string $tenantId) {}

                public function currentTenantId(): ?string
                {
                    return $this->tenantId;
                }
            },
        );
    }
}
