# Installation

## Requirements

- PHP 8.4 or newer.
- Laravel 11, 12, or 13 components.
- A configured Laravel database connection.

## Install With Composer

```bash
composer require mohamedhabibwork/laravel-workflow
```

The package provider and facade alias are auto-discovered through Laravel package discovery.

## Publish Configuration

```bash
php artisan vendor:publish --tag="laravel-workflow-config"
```

This creates `config/workflow.php`.

## Publish and Run Migrations

```bash
php artisan vendor:publish --tag="laravel-workflow-migrations"
php artisan migrate
```

The migration creates workflow definition tables, runtime tables, assignments, and history.

## Verify Installation

```bash
php artisan workflow:list
```

If migrations have not been run, the command returns a clear message asking you to run package migrations first.
