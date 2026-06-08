<?php

/*
 * Configuration for the HFlow Laravel Workflow package.
 *
 * Every value here can be overridden by the host application in their
 * own config/workflow.php file, which the package merges with these
 * defaults at boot.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Table prefix
    |--------------------------------------------------------------------------
    |
    | All workflow tables are created with this prefix. The migration reads
    | this value at runtime, so a host with a custom prefix gets the
    | correct table names without editing the migration.
    |
    | Default: "workflow_"
    |
    */

    'table_prefix' => 'workflow_',

    /*
    |--------------------------------------------------------------------------
    | Database connection
    |--------------------------------------------------------------------------
    |
    | Optional: if set, the engine uses this named connection for all
    | workflow queries. If null, the engine uses the host's default
    | connection.
    |
    */

    'database_connection' => null,

    /*
    |--------------------------------------------------------------------------
    | History retention
    |--------------------------------------------------------------------------
    |
    | How long workflow_histories rows are retained before they can be
    | pruned by the cleanup command. null = forever.
    |
    */

    'history_retention_days' => null,

    /*
    |--------------------------------------------------------------------------
    | Multi-tenancy
    |--------------------------------------------------------------------------
    |
    | When enabled, the engine scopes all queries by the tenant id returned
    | by the TenantScopeProvider. The column is added to definition +
    | instance tables and indexed for fast lookups.
    |
    | tenancy.scope_provider: FQCN of a class implementing
    |     HFlow\LaravelWorkflow\Contracts\TenantScopeProvider
    |     (optional — the engine tolerates null and behaves as single-tenant)
    |
    */

    'tenancy' => [
        'enabled' => false,
        'column' => 'tenant_id',
        'scope_provider' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom host contracts
    |--------------------------------------------------------------------------
    |
    | Each entry is the FQCN of a class implementing the corresponding
    | contract in HFlow\LaravelWorkflow\Contracts. If null, the package's
    | default implementation is bound.
    |
    |   authorizer           -> CustomAuthorizer
    |   condition_evaluator  -> CustomConditionEvaluator
    |   action_handler       -> CustomActionHandler
    |   step_handler         -> CustomStepHandler
    |   resolver             -> CustomResolver
    |
    */

    'custom_contracts' => [
        'authorizer' => null,
        'condition_evaluator' => null,
        'action_handler' => null,
        'step_handler' => null,
        'resolver' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | History event dispatch
    |--------------------------------------------------------------------------
    |
    | fire_laravel_events: when true (default), the engine dispatches typed
    |   Laravel events (e.g. InstanceStarted) after every history INSERT.
    |   The RecordHistory listener converts these into history rows.
    |   When false, the host does not see the typed events but the engine
    |   still records history directly.
    |
    | on_dispatch_failure: "skip" (default) silently ignores listener errors;
    |   "throw" re-throws (useful in tests).
    |
    */

    'events' => [
        'fire_laravel_events' => true,
    ],

    'history' => [
        'on_dispatch_failure' => 'skip',
    ],

    /*
    |--------------------------------------------------------------------------
    | Automation pipeline
    |--------------------------------------------------------------------------
    |
    | max_retry_attempts: how many times the automation runner retries a
    |   failed automated step before marking the instance as failed.
    |
    | retry_backoff_seconds: array of seconds to wait before each retry
    |   attempt. The array length should be >= max_retry_attempts.
    |
    */

    'automation' => [
        'max_retry_attempts' => 3,
        'retry_backoff_seconds' => [10, 60, 300],
        'max_chain_depth' => 50,
    ],

    /*
    |--------------------------------------------------------------------------
    | Artisan commands
    |--------------------------------------------------------------------------
    |
    | Feature flags + tunables for the bundled Artisan commands:
    |   workflow:cleanup-history     — prune rows older than history_retention_days
    |   workflow:activity-feed      — print the activity feed for an instance
    |   workflow:migrate-workflows  — bulk-define a set of workflows from JSON
    |   workflow:cleanup-orphans     — clean up instances whose subject was deleted
    |
    */

    'commands' => [
        'cleanup_history' => [
            'enabled' => true,
            'schedule' => 'daily',
        ],
        'activity_feed' => [
            'enabled' => true,
            'default_per_page' => 25,
            'max_per_page' => 100,
        ],
        'migrate_workflows' => [
            'enabled' => true,
            'batch_size' => 100,
        ],
        'cleanup_orphans' => [
            'enabled' => true,
            'grace_period_hours' => 24,
        ],
    ],

];
