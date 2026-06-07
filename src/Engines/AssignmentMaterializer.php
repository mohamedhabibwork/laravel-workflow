<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Engines;

use HFlow\LaravelWorkflow\Enums\AssigneeType;
use HFlow\LaravelWorkflow\Enums\AssignmentStatus;
use HFlow\LaravelWorkflow\Models\WorkflowAssignment;
use HFlow\LaravelWorkflow\Models\WorkflowStepAssignee;
use Illuminate\Support\Collection;

/**
 * When a `task` / `approval` step is entered, materialize one
 * `WorkflowAssignment` row per resolved assignee with `status = pending`.
 *
 * The `workflow_assignments` schema carries a single integer `assignee_id`
 * (host-user primary key), not the type/value pair from `workflow_step_assignees`.
 * This materializer collapses the type/value pair into a list of integer ids:
 *
 *   - `assignee_type = user`   → one row per `(int) assignee_value`
 *   - `assignee_type = role`   → one row per user id resolved via the host
 *     (Spatie roles / Laravel Gate / custom); deferred when the host does
 *     not bind a role-resolver in the container
 *   - `assignee_type = custom` → one row per user id returned by the host's
 *     `CustomResolver` (registered FQCN in `WorkflowStepAssignee.custom_resolver`)
 */
final class AssignmentMaterializer
{
    /**
     * @return Collection<int, WorkflowAssignment>
     */
    public function materialize(int $stepInstanceId): Collection
    {
        $stepInstance = \HFlow\LaravelWorkflow\Models\WorkflowStepInstance::query()
            ->with('step.assignees')
            ->findOrFail($stepInstanceId);

        $rows = $stepInstance->step->assignees;

        $created = [];
        foreach ($rows as $row) {
            /** @var WorkflowStepAssignee $row */
            foreach ($this->resolveAssigneeIds($row) as $assigneeId) {
                $assignment = new WorkflowAssignment;
                $assignment->fill([
                    'step_instance_id' => $stepInstance->id,
                    'assignee_id' => $assigneeId,
                    'status' => AssignmentStatus::Pending,
                    'assigned_at' => \Illuminate\Support\Carbon::now(),
                ]);
                $assignment->save();
                $created[] = $assignment;
            }
        }

        return collect($created);
    }

    /**
     * Resolve a {@see WorkflowStepAssignee} row to a list of integer user ids.
     *
     * @return list<int>
     */
    private function resolveAssigneeIds(WorkflowStepAssignee $row): array
    {
        $typeValue = $row->assignee_type instanceof AssigneeType
            ? $row->assignee_type->value
            : (string) $row->assignee_type;

        if ($typeValue === AssigneeType::User->value) {
            $id = (int) $row->assignee_value;

            return $id > 0 ? [$id] : [];
        }

        if ($typeValue === AssigneeType::Role->value) {
            // Defer role → user-id expansion to the host via a bound resolver.
            // The host may register a `RoleUserResolver` closure in the container
            // that takes (string $role) and returns list<int>.
            $resolver = app()->bound('workflow.role_user_resolver')
                ? app()->make('workflow.role_user_resolver')
                : null;
            if (is_callable($resolver)) {
                $ids = (array) $resolver((string) $row->assignee_value);

                return array_values(array_map('intval', array_filter($ids, 'is_numeric')));
            }

            return [];
        }

        if ($typeValue === AssigneeType::Custom->value) {
            $fqcn = (string) ($row->custom_resolver ?? '');
            if ($fqcn === '' || ! class_exists($fqcn)) {
                return [];
            }
            $impl = app($fqcn);
            if (! is_callable($impl)) {
                return [];
            }
            $ids = (array) $impl();

            return array_values(array_map('intval', array_filter($ids, 'is_numeric')));
        }

        return [];
    }
}
