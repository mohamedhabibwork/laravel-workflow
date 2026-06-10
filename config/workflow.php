<?php

use HFlow\LaravelWorkflow\Builders\WorkflowBuilder;
use HFlow\LaravelWorkflow\LaravelWorkflow;
use HFlow\LaravelWorkflow\Services\ActionResolver;
use HFlow\LaravelWorkflow\Services\ActivityService;
use HFlow\LaravelWorkflow\Services\AttributeWorkflowRegistrar;
use HFlow\LaravelWorkflow\Services\ConditionEvaluator;
use HFlow\LaravelWorkflow\Services\WorkflowEngine;
use HFlow\LaravelWorkflow\Services\WorkflowService;
use Illuminate\Support\Env;

// config for HFlow/LaravelWorkflow
return [
    /**
     * The table prefix for the workflow engine tables.
     */
    'table_prefix' => Env::get('WORKFLOW_TABLE_PREFIX', 'workflow_'),

    /**
     * Whether to use multi-tenancy support.
     */
    'multi_tenancy' => [
        'enabled' => Env::get('WORKFLOW_TENANCY_ENABLED', false),
        'column' => 'tenant_id',
        'current_tenant_id' => null,
        'resolver' => null,
    ],

    /*
     * Attribute-first workflow definitions.
     *
     * Add fully-qualified workflow definition class names here, then run:
     * php artisan workflow:sync-attributes
     */
    'attributes' => [
        'workflows' => [
            // App\Workflows\OrderApprovalWorkflow::class,
        ],
    ],

    'activities' => [
        'retry_delay_seconds' => Env::get('WORKFLOW_ACTIVITY_RETRY_DELAY_SECONDS', 5),
    ],

    /*
     * Override package classes without changing package source.
     *
     * Custom classes should extend the configured package class so the public
     * API contract remains compatible with the rest of the package.
     */
    'classes' => [
        'api' => LaravelWorkflow::class,
        'workflow_builder' => WorkflowBuilder::class,
        'workflow_engine' => WorkflowEngine::class,
        'workflow_service' => WorkflowService::class,
        'attribute_workflow_registrar' => AttributeWorkflowRegistrar::class,
        'activity_service' => ActivityService::class,
        'action_resolver' => ActionResolver::class,
        'condition_evaluator' => ConditionEvaluator::class,
    ],
];
