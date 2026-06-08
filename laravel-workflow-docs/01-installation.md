# Installation

## Requirements

- PHP `^8.4`
- Laravel 10, 11, 12, or 13
- A relational database supported by Laravel migrations
- Composer

The package is tested with Orchestra Testbench, Pest, Larastan/PHPStan, and Laravel Pint.

## Install The Package

```bash
composer require mohamedhabibwork/laravel-workflow
```

## Publish And Run The Migration

```bash
php artisan vendor:publish --tag=laravel-workflow-migrations
php artisan migrate
```

The package ships one combined migration. It creates all definition and runtime tables using `config('workflow.table_prefix')`. With the default prefix, physical table names include:

- `workflow_workflows`
- `workflow_steps`
- `workflow_step_assignees`
- `workflow_step_actions`
- `workflow_conditions`
- `workflow_transitions`
- `workflow_instances`
- `workflow_step_instances`
- `workflow_assignments`
- `workflow_histories`

## Publish The Config

```bash
php artisan vendor:publish --tag=laravel-workflow-config
```

After publishing, edit `config/workflow.php` for table prefix, tenancy, custom contracts, automation retry behavior, and attribute compile paths.

## Verify The Install

```bash
php artisan workflow:list
php artisan workflow:diagnose
```

Both commands should run without errors. `workflow:list` may say that no workflows exist yet.

## Next Step

Read [Defining Workflows](./04-defining-workflows.md) to create and activate the first workflow, or [PHP Attributes](./06-php-attributes.md) if you prefer typed workflow classes.

