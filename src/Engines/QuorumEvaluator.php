<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Engines;

use HFlow\LaravelWorkflow\Enums\AssignmentStatus;
use HFlow\LaravelWorkflow\Enums\MatchMode;
use HFlow\LaravelWorkflow\Models\WorkflowAssignment;
use Illuminate\Support\Facades\DB;

/**
 * Quorum evaluation for `any` / `all` match modes.
 *
 *   - any  : the first acted assignment satisfies the step; remaining
 *            pending ones are marked `expired` and returned.
 *   - all  : the step is satisfied only when every pending assignment
 *            is acted.
 */
final class QuorumEvaluator
{
    /**
     * Evaluate the quorum for the given step instance and return
     * `true` if the step may now transition to its next state.
     */
    public function isSatisfied(int $stepInstanceId): bool
    {
        $assignments = WorkflowAssignment::query()
            ->where('step_instance_id', $stepInstanceId)
            ->cursor();

        if ($assignments->isEmpty()) {
            return true; // no quorum required
        }

        $mode = $this->matchMode($stepInstanceId);
        $acted = $assignments->where('status', AssignmentStatus::Acted)->count();
        $pending = $assignments->where('status', AssignmentStatus::Pending)->count();

        return match ($mode) {
            MatchMode::Any => $acted >= 1,
            MatchMode::All => $pending === 0 && $acted >= 1,
            default => $acted >= 1,
        };
    }

    /**
     * For `match_mode = any`, mark remaining pending assignments as
     * `expired` and return their ids. Returns an empty array otherwise.
     *
     * @return list<int>
     */
    public function expirePending(int $stepInstanceId): array
    {
        if ($this->matchMode($stepInstanceId) !== MatchMode::Any) {
            return [];
        }

        return DB::transaction(function () use ($stepInstanceId): array {
            $pending = WorkflowAssignment::query()
                ->where('step_instance_id', $stepInstanceId)
                ->where('status', AssignmentStatus::Pending)
                ->cursor();

            $ids = [];
            foreach ($pending as $a) {
                $a->status = AssignmentStatus::Expired;
                $a->save();
                $ids[] = (int) $a->id;
            }

            return $ids;
        });
    }

    private function matchMode(int $stepInstanceId): MatchMode
    {
        $stepInstance = \HFlow\LaravelWorkflow\Models\WorkflowStepInstance::query()
            ->with('step')
            ->find($stepInstanceId);

        if (! $stepInstance || ! $stepInstance->step) {
            return MatchMode::Any;
        }

        return $stepInstance->step->match_mode;
    }
}
