<?php

declare(strict_types=1);

/*
 |--------------------------------------------------------------------------
 | Architecture Tests for HFlow\LaravelWorkflow
 |--------------------------------------------------------------------------
 |
 | These tests enforce structural and coding conventions of the package.
 | They run as part of `vendor/bin/pest` and are cheap (no DB, no I/O).
 |
 */

use HFlow\LaravelWorkflow\Concerns\AppendOnlyHistory;
use HFlow\LaravelWorkflow\Enums\ActionAvailabilityMode;
use HFlow\LaravelWorkflow\Enums\ActionType;
use HFlow\LaravelWorkflow\Enums\ActorType;
use HFlow\LaravelWorkflow\Enums\AssigneeType;
use HFlow\LaravelWorkflow\Enums\AssignmentStatus;
use HFlow\LaravelWorkflow\Enums\AuthorizationMode;
use HFlow\LaravelWorkflow\Enums\ConditionKind;
use HFlow\LaravelWorkflow\Enums\HistoryEvent;
use HFlow\LaravelWorkflow\Enums\InstanceStatus;
use HFlow\LaravelWorkflow\Enums\MatchMode;
use HFlow\LaravelWorkflow\Enums\Operator;
use HFlow\LaravelWorkflow\Enums\StepInstanceStatus;
use HFlow\LaravelWorkflow\Enums\StepType;
use HFlow\LaravelWorkflow\Enums\TransitionType;
use HFlow\LaravelWorkflow\Enums\WorkflowStatus;
use HFlow\LaravelWorkflow\Enums\WorkflowType;
use HFlow\LaravelWorkflow\Exceptions\AppendOnlyViolationException;
use HFlow\LaravelWorkflow\Models\WorkflowHistory;
use HFlow\LaravelWorkflow\StateMachine\AssignmentStateMachine;
use HFlow\LaravelWorkflow\StateMachine\InstanceStateMachine;
use HFlow\LaravelWorkflow\StateMachine\StepInstanceStateMachine;
use HFlow\LaravelWorkflow\StateMachine\WorkflowStateMachine;

arch('it will not use debugging functions')
    ->expect(['dd', 'dump', 'ray'])
    ->each->not->toBeUsed();

arch('all enums are final and string-backed')
    ->expect([
        WorkflowType::class,
        WorkflowStatus::class,
        StepType::class,
        AuthorizationMode::class,
        MatchMode::class,
        AssigneeType::class,
        ActionType::class,
        ActionAvailabilityMode::class,
        ConditionKind::class,
        TransitionType::class,
        InstanceStatus::class,
        StepInstanceStatus::class,
        AssignmentStatus::class,
        HistoryEvent::class,
        ActorType::class,
        Operator::class,
    ])
    ->toBeEnums();

arch('state machines are final and pure-PHP')
    ->expect([
        WorkflowStateMachine::class,
        InstanceStateMachine::class,
        StepInstanceStateMachine::class,
        AssignmentStateMachine::class,
    ])
    ->toBeClasses()
    ->toBeFinal();

if (class_exists(WorkflowHistory::class)) {
    arch('history model uses AppendOnlyHistory trait')
        ->expect(WorkflowHistory::class)
        ->toUseTrait(AppendOnlyHistory::class);
}

if (class_exists(AppendOnlyViolationException::class)) {
    arch('AppendOnlyViolationException is a LogicException')
        ->expect(AppendOnlyViolationException::class)
        ->toExtend(LogicException::class);

    arch('AppendOnlyViolationException is not a RuntimeException')
        ->expect(AppendOnlyViolationException::class)
        ->not->toExtend(RuntimeException::class);
}

arch('all PHP source files declare strict types')
    ->expect('HFlow\\LaravelWorkflow')
    ->toUseStrictTypes();

if (is_dir(__DIR__.'/../src/Models')) {
    arch('all Eloquent models (except abstract base) are final')
        ->expect('HFlow\\LaravelWorkflow\\Models')
        ->toBeClasses()
        ->toBeFinal()
        ->ignoring(['HFlow\\LaravelWorkflow\\Models\\WorkflowModel']);
}

// T053 — Append-only invariant: no class in src/Engines/ (other than HistoryRecorder)
// may call ->update() or ->delete() on a WorkflowHistory instance.
if (is_dir(__DIR__.'/../src/Engines')) {
    it('src/Engines/ never calls ->update() or ->delete() on a WorkflowHistory instance (append-only invariant)', function (): void {
        $enginesDir = realpath(__DIR__.'/../src/Engines');
        $historyFqcn = 'HFlow\\LaravelWorkflow\\Models\\WorkflowHistory';
        $recorderFqcn = 'HFlow\\LaravelWorkflow\\Observability\\HistoryRecorder';

        $offenders = [];

        $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($enginesDir));

        foreach ($rii as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = ltrim(str_replace($enginesDir, '', $file->getPathname()), '/');
            $source = file_get_contents($file->getPathname());
            if ($source === false) {
                continue;
            }

            // Skip the recorder class itself - it is the one place that
            // writes to workflow_histories (and only via direct INSERT).
            if (str_contains($source, 'class HistoryRecorder')
                || str_contains($source, $recorderFqcn)
            ) {
                continue;
            }

            // Look for ->update( or ->delete( on a variable that could be a
            // WorkflowHistory. We match any call like $x->update(...) or
            // ->delete(...) and flag if the file imports or references
            // WorkflowHistory.
            if (str_contains($source, $historyFqcn)) {
                if (preg_match('/->(update|delete)\s*\(/', $source, $matches, PREG_OFFSET_CAPTURE) === 1) {
                    $line = substr_count(substr($source, 0, $matches[0][1]), "\n") + 1;
                    $offenders[] = "{$relative}:{$line} ({$matches[0][0]})";
                }
            }
        }

        expect($offenders)
            ->toBe([], "The following files in src/Engines/ call ->update() or ->delete() on a WorkflowHistory (append-only invariant violation):\n".implode("\n", $offenders));
    });
}
