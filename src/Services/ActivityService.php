<?php

namespace HFlow\LaravelWorkflow\Services;

use Carbon\CarbonInterface;
use HFlow\LaravelWorkflow\Contracts\ActivityHandler;
use HFlow\LaravelWorkflow\Enums\ActivityStatus;
use HFlow\LaravelWorkflow\Enums\ActorType;
use HFlow\LaravelWorkflow\Enums\HistoryEvent;
use HFlow\LaravelWorkflow\Events\WorkflowHistoryRecorded;
use HFlow\LaravelWorkflow\Models\WorkflowActivity;
use HFlow\LaravelWorkflow\Models\WorkflowHistory;
use HFlow\LaravelWorkflow\Models\WorkflowInstance;
use HFlow\LaravelWorkflow\Support\ActivityResult;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ActivityService
{
    public function __construct(
        protected ?Container $container = null,
    ) {
        $this->container ??= \Illuminate\Container\Container::getInstance();
    }

    /**
     * @param  class-string  $handler
     * @param  array<string, mixed>  $input
     * @param  array{
     *     step_instance_id?: int,
     *     task_queue?: string,
     *     max_attempts?: int,
     *     retry_delay_seconds?: int,
     *     available_at?: CarbonInterface,
     *     schedule_to_close_timeout_seconds?: int,
     *     start_to_close_timeout_seconds?: int
     * }  $options
     */
    public function schedule(WorkflowInstance $instance, string $name, string $handler, array $input = [], array $options = []): WorkflowActivity
    {
        return DB::transaction(function () use ($instance, $name, $handler, $input, $options) {
            $activity = WorkflowActivity::create([
                'tenant_id' => $instance->tenant_id,
                'workflow_instance_id' => $instance->id,
                'step_instance_id' => $options['step_instance_id'] ?? null,
                'name' => $name,
                'handler' => $handler,
                'task_queue' => $options['task_queue'] ?? null,
                'status' => ActivityStatus::Pending,
                'input' => $input,
                'attempt' => 0,
                'max_attempts' => max(1, (int) ($options['max_attempts'] ?? 1)),
                'available_at' => $options['available_at'] ?? now(),
                'schedule_to_close_timeout_at' => isset($options['schedule_to_close_timeout_seconds'])
                    ? now()->addSeconds((int) $options['schedule_to_close_timeout_seconds'])
                    : null,
                'start_to_close_timeout_at' => isset($options['start_to_close_timeout_seconds'])
                    ? now()->addSeconds((int) $options['start_to_close_timeout_seconds'])
                    : null,
            ]);

            $this->log($instance, $activity, HistoryEvent::ActivityScheduled, [
                'name' => $name,
                'handler' => $handler,
            ]);

            return $activity;
        });
    }

    /**
     * @return Collection<int, WorkflowActivity>
     */
    public function runDue(?string $taskQueue = null, int $limit = 50, ?CarbonInterface $now = null): Collection
    {
        $now ??= now();
        $processed = collect();

        WorkflowActivity::query()
            ->where('status', ActivityStatus::Pending)
            ->where('available_at', '<=', $now)
            ->when($taskQueue !== null, fn ($query) => $query->where('task_queue', $taskQueue))
            ->orderBy('available_at')
            ->limit($limit)
            ->get()
            ->each(function (WorkflowActivity $activity) use ($processed) {
                $this->run($activity);
                $processed->push($activity->fresh());
            });

        $this->processTimeouts($now);

        return $processed;
    }

    public function run(WorkflowActivity $activity): WorkflowActivity
    {
        return DB::transaction(function () use ($activity) {
            $activity->refresh();

            if ($activity->status !== ActivityStatus::Pending) {
                return $activity;
            }

            $activity->update([
                'status' => ActivityStatus::Running,
                'attempt' => $activity->attempt + 1,
                'started_at' => now(),
                'heartbeat_at' => now(),
            ]);

            $this->log($activity->workflowInstance, $activity, HistoryEvent::ActivityStarted);

            try {
                $handler = $this->container->make($activity->handler);

                if (! $handler instanceof ActivityHandler) {
                    throw new \Exception("Activity handler '{$activity->handler}' must implement ActivityHandler.");
                }

                $result = $handler->handle($activity->fresh());

                if ($result instanceof ActivityResult && $result->async) {
                    $activity->update([
                        'status' => ActivityStatus::WaitingForCompletion,
                        'async_token' => $activity->async_token ?? (string) Str::uuid(),
                    ]);

                    $this->log($activity->workflowInstance, $activity, HistoryEvent::ActivityWaiting, [
                        'async_token' => $activity->async_token,
                    ]);

                    return $activity->fresh();
                }

                $payload = $result instanceof ActivityResult ? $result->result : $result;

                return $this->complete($activity, $payload);
            } catch (\Exception $exception) {
                return $this->fail($activity, $exception->getMessage());
            }
        });
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public function complete(WorkflowActivity $activity, array $result = []): WorkflowActivity
    {
        $activity->update([
            'status' => ActivityStatus::Completed,
            'result' => $result,
            'completed_at' => now(),
            'error' => null,
        ]);

        $this->log($activity->workflowInstance, $activity, HistoryEvent::ActivityCompleted, [
            'result' => $result,
        ]);

        return $activity->fresh();
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public function completeAsync(string $token, array $result = []): WorkflowActivity
    {
        $activity = WorkflowActivity::query()
            ->where('async_token', $token)
            ->where('status', ActivityStatus::WaitingForCompletion)
            ->firstOrFail();

        return DB::transaction(fn () => $this->complete($activity, $result));
    }

    public function fail(WorkflowActivity $activity, string $error): WorkflowActivity
    {
        if ($activity->attempt < $activity->max_attempts) {
            $activity->update([
                'status' => ActivityStatus::Pending,
                'error' => $error,
                'available_at' => now()->addSeconds((int) config('workflow.activities.retry_delay_seconds', 5)),
            ]);

            $this->log($activity->workflowInstance, $activity, HistoryEvent::ActivityFailed, [
                'error' => $error,
                'retrying' => true,
            ]);

            return $activity->fresh();
        }

        $activity->update([
            'status' => ActivityStatus::Failed,
            'error' => $error,
            'completed_at' => now(),
        ]);

        $this->log($activity->workflowInstance, $activity, HistoryEvent::ActivityFailed, [
            'error' => $error,
            'retrying' => false,
        ]);

        return $activity->fresh();
    }

    public function heartbeat(WorkflowActivity $activity): void
    {
        $activity->update(['heartbeat_at' => now()]);
    }

    /**
     * @return Collection<int, WorkflowActivity>
     */
    public function processTimeouts(?CarbonInterface $now = null): Collection
    {
        $now ??= now();
        $timedOut = collect();

        WorkflowActivity::query()
            ->whereIn('status', [
                ActivityStatus::Pending,
                ActivityStatus::Running,
                ActivityStatus::WaitingForCompletion,
            ])
            ->where(function ($query) use ($now) {
                $query
                    ->whereNotNull('schedule_to_close_timeout_at')
                    ->where('schedule_to_close_timeout_at', '<=', $now)
                    ->orWhere(function ($query) use ($now) {
                        $query
                            ->whereNotNull('start_to_close_timeout_at')
                            ->where('start_to_close_timeout_at', '<=', $now);
                    });
            })
            ->get()
            ->each(function (WorkflowActivity $activity) use ($timedOut) {
                $activity->update([
                    'status' => ActivityStatus::TimedOut,
                    'completed_at' => now(),
                    'error' => 'Activity timed out.',
                ]);

                $this->log($activity->workflowInstance, $activity, HistoryEvent::ActivityTimedOut);
                $timedOut->push($activity->fresh());
            });

        return $timedOut;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    protected function log(WorkflowInstance $instance, WorkflowActivity $activity, HistoryEvent $event, array $metadata = []): void
    {
        $history = WorkflowHistory::create([
            'workflow_instance_id' => $instance->id,
            'step_instance_id' => $activity->step_instance_id,
            'event' => $event,
            'actor_type' => ActorType::System,
            'metadata' => array_replace([
                'activity_id' => $activity->id,
                'activity_name' => $activity->name,
                'attempt' => $activity->attempt,
            ], $metadata),
            'performed_at' => now(),
        ]);

        event(new WorkflowHistoryRecorded($history));
    }
}
