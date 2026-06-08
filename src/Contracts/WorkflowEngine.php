<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Contracts;

use HFlow\LaravelWorkflow\Actions\ActionSet;
use HFlow\LaravelWorkflow\Exceptions\AutomationLoopGuardException;
use HFlow\LaravelWorkflow\Exceptions\InvalidStateException;
use HFlow\LaravelWorkflow\Exceptions\ReturnNotAllowedException;
use HFlow\LaravelWorkflow\Exceptions\SkipNotAllowedException;
use HFlow\LaravelWorkflow\Exceptions\WorkflowTerminalException;
use HFlow\LaravelWorkflow\Facades\LaravelWorkflow;
use HFlow\LaravelWorkflow\Models\Workflow;
use HFlow\LaravelWorkflow\Models\WorkflowHistory;
use HFlow\LaravelWorkflow\Models\WorkflowInstance;
use HFlow\LaravelWorkflow\Models\WorkflowStep;
use HFlow\LaravelWorkflow\Models\WorkflowStepInstance;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Public service contract of the workflow engine.
 *
 * This is the ONLY class a host MUST know to use the engine. All other
 * classes in the package are implementation details. The interface is
 * bound in the service container; hosts may type-hint it in their
 * constructors, or use the {@see LaravelWorkflow}
 * facade.
 *
 * @see \HFlow\LaravelWorkflow\Engines\WorkflowEngine  Default implementation
 * @see /specs/002-laravel-workflow-engine/contracts/workflow-engine.md  Full contract
 */
interface WorkflowEngine
{
    // -------------------------------------------------------------------
    //  Definition (US1)
    // -------------------------------------------------------------------

    /**
     * Define a new draft workflow from a structured array.
     *
     * @param  string  $key  Logical workflow key (e.g. "order-approval")
     * @param  array<string, mixed>  $definition  Workflow definition graph
     * @return Workflow Newly-created draft workflow
     */
    public function define(string $key, array $definition): Workflow;

    /**
     * Activate a draft workflow. The previous active version (if any)
     * for the same key is flipped to `is_current_version = false`.
     */
    public function activate(Workflow|string $workflow): Workflow;

    /**
     * Return all versions of a workflow (newest first).
     *
     * @return Collection<int, Workflow>
     */
    public function versions(Workflow|string $workflow): Collection;

    /**
     * Create a new draft version of an existing workflow.
     *
     * @param  array<string, mixed>  $overrides  Optional mutations to apply
     */
    public function createNewVersion(Workflow $workflow, array $overrides = []): Workflow;

    // -------------------------------------------------------------------
    //  Runtime (US2 + US3)
    // -------------------------------------------------------------------

    /**
     * Start a new instance of a workflow bound to a host model record.
     *
     * @param  Model  $subject  The host model to bind (polymorphic)
     * @param  array<string, mixed>  $context  Initial runtime data bag
     * @param  mixed  $initiator  The user starting the instance (or null = system)
     */
    public function start(
        Workflow|string $workflow,
        Model $subject,
        array $context = [],
        mixed $initiator = null,
    ): WorkflowInstance;

    /**
     * Get the active step instance(s) for an instance.
     *
     * For non-parallel workflows, returns a single WorkflowStepInstance.
     * For parallel forks, returns a Collection of WorkflowStepInstance.
     *
     * @return WorkflowStepInstance|Collection<int, WorkflowStepInstance>
     */
    public function currentStep(WorkflowInstance $instance): WorkflowStepInstance|Collection;

    /**
     * Resolve the set of actions a user may perform on the instance right now.
     *
     * The result is a deterministic snapshot of the current state.
     */
    public function availableActions(WorkflowInstance $instance, mixed $user = null): ActionSet;

    /**
     * Perform an action and advance the instance.
     *
     * @param  string  $actionCode  The key of the action to perform
     * @param  array<string, mixed>|null  $payload  Optional {comment, target_step_id, metadata}
     */
    public function perform(
        WorkflowInstance $instance,
        string $actionCode,
        mixed $user = null,
        ?array $payload = null,
    ): WorkflowInstance;

    // -------------------------------------------------------------------
    //  Skip / Return (US4)
    // -------------------------------------------------------------------

    /**
     * Skip the current step.
     *
     * Throws SkipNotAllowedException when the step is not skippable or
     * the skip guard fails. Throws WorkflowTerminalException when the
     * instance is already terminal.
     *
     * @throws SkipNotAllowedException
     * @throws WorkflowTerminalException
     */
    public function skip(
        WorkflowInstance $instance,
        mixed $user = null,
        ?string $comment = null,
    ): WorkflowInstance;

    /**
     * Return to an earlier step.
     *
     * Throws ReturnNotAllowedException when the step is not returnable or
     * the return guard fails. Throws WorkflowTerminalException when the
     * instance is already terminal.
     *
     * @param  WorkflowStep|string|null  $targetStep  Target to return to.
     *                                                `null` = most recently
     *                                                completed step in the
     *                                                instance.
     *
     * @throws ReturnNotAllowedException
     * @throws WorkflowTerminalException
     */
    public function return(
        WorkflowInstance $instance,
        WorkflowStep|string|null $targetStep = null,
        mixed $user = null,
        ?string $comment = null,
    ): WorkflowInstance;

    // -------------------------------------------------------------------
    //  Automation (US5)
    // -------------------------------------------------------------------

    /**
     * Re-enter the most recently failed step as a fresh step instance
     * and resume the automation chain.
     *
     * Asserts the instance is currently `failed` (else InvalidStateException).
     * If the failed step is `automated`, the AutomationRunner is invoked
     * synchronously so the chain continues until a human-gated step,
     * an `end` step, or a second failure.
     *
     * @throws InvalidStateException
     * @throws AutomationLoopGuardException
     */
    public function retry(
        WorkflowInstance $instance,
        mixed $user = null,
        ?string $comment = null,
    ): WorkflowInstance;

    // -------------------------------------------------------------------
    //  Hold / Resume / Cancel (US7)
    // -------------------------------------------------------------------

    /**
     * Put the instance on hold.
     */
    public function hold(
        WorkflowInstance $instance,
        mixed $user = null,
        ?string $comment = null,
    ): WorkflowInstance;

    /**
     * Resume a held instance.
     */
    public function resume(
        WorkflowInstance $instance,
        mixed $user = null,
    ): WorkflowInstance;

    /**
     * Cancel the instance.
     */
    public function cancel(
        WorkflowInstance $instance,
        mixed $user = null,
        ?string $comment = null,
    ): WorkflowInstance;

    // -------------------------------------------------------------------
    //  Activity feed (US6)
    // -------------------------------------------------------------------

    /**
     * Return the activity feed (history rows) for an instance.
     *
     * @param  int|null  $limit  If set, return at most this many events (most recent first)
     * @param  string|null  $event  If set, filter to a single event type
     * @return Collection<int, WorkflowHistory>
     */
    public function history(
        WorkflowInstance $instance,
        ?int $limit = null,
        ?string $event = null,
    ): Collection;
}
