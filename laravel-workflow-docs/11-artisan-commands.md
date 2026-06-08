# Artisan Commands

## `workflow:list`

Lists workflows, ordered by code and version.

```bash
php artisan workflow:list
php artisan workflow:list --status=active
php artisan workflow:list --all
```

Columns include code, name, version, status, type, subject type, and tenant.

## `workflow:status`

Shows one workflow instance and a recent history tail.

```bash
php artisan workflow:status {instance-uuid}
php artisan workflow:status {instance-uuid} --tail=10
```

If no instance UUID is passed, the command prompts for one.

## `workflow:history`

Prints the activity feed for an instance.

```bash
php artisan workflow:history {instance-uuid}
php artisan workflow:history {instance-uuid} --limit=50
```

The limit is capped by `workflow.commands.activity_feed.max_per_page`.

## `workflow:diagnose`

Checks workflow structure.

```bash
php artisan workflow:diagnose
php artisan workflow:diagnose order-approval
php artisan workflow:diagnose order-approval --all
```

It reports issues such as:

- missing start/end steps
- multiple start/end steps
- dangling transitions
- actions without handlers

## `workflow:compile-attributes`

Compiles PHP attribute workflow classes into definition rows.

```bash
php artisan workflow:compile-attributes
php artisan workflow:compile-attributes --path=app/Workflows
php artisan workflow:compile-attributes --dry-run
php artisan workflow:compile-attributes --tenant=10
php artisan workflow:compile-attributes --workflow-version=2
```

The command exits `0` on success and `1` on validation or runtime failure.

