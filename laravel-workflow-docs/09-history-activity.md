# History And Activity

History is append-only and is the source for the activity feed.

## History Events

| Event | Typical source |
|---|---|
| `started` | `start()` |
| `step_entered` | entering a new step |
| `step_completed` | completing/leaving a step |
| `action_performed` | `perform()` |
| `skipped` | `skip()` |
| `returned` | `return()` |
| `completed` | reaching an end step |
| `cancelled` | `cancel()` |
| `comment_added` | action/operation comment metadata |
| `on_hold` | `hold()` |
| `resumed` | `resume()` |
| `error` | automation or handler failure |

## Columns

`workflow_histories` stores:

- `workflow_instance_id`
- `step_instance_id`
- `from_step_id`
- `to_step_id`
- `action_code`
- `event`
- `actor_id`
- `actor_type`
- `comment`
- `metadata`
- `performed_at`
- `created_at`

It intentionally has no `updated_at`, no `deleted_at`, and no `is_deleted`.

## Reading History

```php
$events = $engine->history($instance);
$lastTen = $engine->history($instance, limit: 10);
$actions = $engine->history($instance, event: 'action_performed');
```

The package eager-loads step relations for feed rendering.

## Rendering A Feed

```php
foreach ($engine->history($instance, limit: 20) as $event) {
    printf(
        '[%s] %s by %s: %s'.PHP_EOL,
        $event->performed_at?->toIso8601String(),
        $event->event->value,
        $event->actor_id ?? 'system',
        $event->comment ?? ''
    );
}
```

## Audit Guarantee

Control operations such as `return()` and `retry()` create fresh step instances. They do not rewrite completed, returned, skipped, or failed history.

