# Testing And Operations

## Package Checks

Run locally:

```bash
composer install
vendor/bin/pint --test
composer analyse
vendor/bin/pest --stop-on-failure
```

## Host App Smoke Test

After installing the package in a host app:

1. Publish and run migrations.
2. Create a draft workflow with one start step and one end step.
3. Activate it.
4. Start an instance on a host Eloquent model.
5. Confirm `currentStep()` returns an active step instance.
6. Perform an action.
7. Confirm history rows were written.

## CI

The package includes:

- `.github/workflows/tests.yml` for the Laravel/Testbench matrix
- `.github/workflows/lint.yml` for Pint and PHPStan

Recommended host-app jobs:

```bash
php artisan migrate --env=testing
php artisan workflow:compile-attributes --dry-run
php artisan workflow:diagnose
vendor/bin/pest
```

## Production Notes

- Keep history rows unless your compliance policy explicitly allows pruning.
- Use `workflow:diagnose` before activating complex workflow changes.
- Prefer idempotent handlers for external side effects.
- Keep custom authorizers and conditions pure; they are called more than once.
- Use `automation.max_chain_depth` as a hard safety guard.

