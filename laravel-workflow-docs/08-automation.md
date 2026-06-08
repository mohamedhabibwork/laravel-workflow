# Automation

Automation is built from `automated` steps and `CustomStepHandler` implementations.

## Automated Step Handler

```php
namespace App\Workflow\Steps;

use HFlow\LaravelWorkflow\Contracts\CustomStepHandler;
use HFlow\LaravelWorkflow\Models\WorkflowInstance;
use HFlow\LaravelWorkflow\Models\WorkflowStepInstance;

final class EnrichOrder implements CustomStepHandler
{
    public function handle(WorkflowInstance $instance, WorkflowStepInstance $stepInstance): array
    {
        $order = $instance->workflowable;

        return [
            'risk_score' => $order->calculateRiskScore(),
        ];
    }
}
```

Store the handler FQCN on the automated step's `handler` column or pass it through the `#[Step(handler: ...)]` attribute.

## Runtime Behavior

When the engine enters an automated step:

1. It invokes the step handler.
2. It merges returned data into the step instance `data` column.
3. It closes the automated step.
4. It resolves the next transition.
5. It continues until it reaches:
   - a human-gated step
   - an end step
   - a failure
   - `automation.max_chain_depth`

## Failure And Retry

If a handler throws:

- the step instance becomes `failed`
- the workflow instance becomes `failed`
- an `error` history event is recorded

Retry with:

```php
$instance = $engine->retry($failedInstance, auth()->user(), 'Retry after service recovery.');
```

Retry creates a fresh step instance. It does not mutate the failed one.

## Loop Guard

`automation.max_chain_depth` defaults to `50`. Raise it only when you intentionally have long automation chains.

