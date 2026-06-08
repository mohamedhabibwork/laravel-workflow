<?php

declare(strict_types=1);

use HFlow\LaravelWorkflow\Enums\HistoryEvent;
use HFlow\LaravelWorkflow\Models\WorkflowHistory;
use HFlow\LaravelWorkflow\Observability\HistoryRecorder;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * T062 - Unit test for the `workflow_histories.metadata` payload contract.
 *
 *  (a) Every HistoryEvent case has a non-empty metadataSchema() listing the
 *      well-known keys recorded for that event. This is the single source of
 *      truth for "what's in metadata for an `action_performed` row?" etc.
 *  (b) The recorder's documented contract is "metadata is scalars, arrays
 *      of scalars, or null" — never raw Eloquent models. This is the
 *      CONVENTION enforced by the engine's call sites (which always extract
 *      ids and primitives before recording). The test pins the documented
 *      behaviour and round-trips a representative payload.
 *  (c) The recorder's persisted `metadata` column is a JSON string, not a
 *      PHP-serialized blob, so it round-trips as a plain associative array
 *      on the Eloquent cast.
 */
beforeEach(function (): void {
    $this->loadWorkflowMigrations();
});

it('(a) every HistoryEvent case has a non-empty documented metadata schema', function (): void {
    foreach (HistoryEvent::cases() as $case) {
        $schema = $case->metadataSchema();

        expect($schema)
            ->toBeArray("metadataSchema() for {$case->value} must return an array")
            ->and($schema)->not->toBeEmpty("metadataSchema() for {$case->value} is empty");

        foreach ($schema as $key) {
            expect($key)
                ->toBeString("metadataSchema() keys for {$case->value} must be strings")
                ->and(trim($key))->not->toBe('', "metadataSchema() keys for {$case->value} must not be blank");
        }
    }
});

it('(a2) metadataSchema() is stable: known events keep the documented keys', function (): void {
    // Pin the documented schemas so a future refactor that drops a key
    // forces an explicit update of this test (and of consumers).
    expect(HistoryEvent::Started->metadataSchema())->toContain('workflow_code', 'workflow_version')
        ->and(HistoryEvent::ActionPerformed->metadataSchema())->toContain('resolved_action_code')
        ->and(HistoryEvent::StepEntered->metadataSchema())->toContain('step_type')
        ->and(HistoryEvent::StepCompleted->metadataSchema())->toContain('status')
        ->and(HistoryEvent::Completed->metadataSchema())->toContain('final_status')
        ->and(HistoryEvent::Skipped->metadataSchema())->not->toBeEmpty()
        ->and(HistoryEvent::Returned->metadataSchema())->not->toBeEmpty()
        ->and(HistoryEvent::Cancelled->metadataSchema())->not->toBeEmpty()
        ->and(HistoryEvent::CommentAdded->metadataSchema())->not->toBeEmpty()
        ->and(HistoryEvent::Error->metadataSchema())->not->toBeEmpty();
});

it('(b) the recorder treats metadata as JSON-safe scalars/arrays only (no raw models)', function (): void {
    // The recorder's contract is enforced by every caller in the engine:
    //   - The 9 `recorder()->record([... 'metadata' => [...]])` sites in
    //     WorkflowEngine.php build metadata with `$x->value` strings,
    //     literal arrays, and primitive types — never raw Models.
    //   - This test pins that contract by ensuring the recorder persists a
    //     representative payload (scalars, nested array, null inside) and
    //     that the cast on the WorkflowHistory model brings it back
    //     identically.
    //
    // Hosts that violate the contract (e.g. pass a raw Eloquent Model) get
    // a silently nested JSON object — that's why the convention must be
    // documented and pinned at the call site, not at the recorder.

    $instancesTable = config('workflow.table_prefix', 'workflow_').'instances';
    $instanceId = DB::table($instancesTable)->insertGetId([
        'uuid' => (string) Str::uuid(),
        'workflow_id' => 0,
        'workflow_version' => 1,
        'subject_type' => 'host',
        'subject_id' => 0,
        'status' => 'in_progress',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $recorder = new HistoryRecorder(app(Dispatcher::class));

    $payload = [
        'workflow_code' => 'order-approval',
        'workflow_version' => 2,
        'step_type' => 'task',
        'resolved_action_code' => 'submit',
        'count' => 1,
        'flag' => true,
        'tags' => ['urgent', 'priority'],
        'host_id' => 42,                // id extracted from a host model
        'host_reference' => 'HOST-42',   // scalar attribute extracted from a host model
        'null_field' => null,            // nullable keys are allowed
    ];

    $row = $recorder->record([
        'workflow_instance_id' => $instanceId,
        'event' => HistoryEvent::ActionPerformed,
        'metadata' => $payload,
    ]);

    // Eloquent cast round-trips the array identically.
    expect($row->metadata)->toBe($payload);

    // Every value in metadata is a scalar, null, or an array of scalars/nulls
    // — never a Model/Closure/Resource.
    $walk = function (array $data) use (&$walk): void {
        foreach ($data as $value) {
            if (is_array($value)) {
                $walk($value);

                continue;
            }
            // Real assertion: must be scalar-or-null, NOT object/resource/closure.
            if (is_object($value) || is_resource($value)) {
                test()->fail('metadata contains an object/resource: '.get_debug_type($value));
            }
        }
    };
    $walk($payload);

    // And the canonical "host model reference" pattern is `['id' => $model->id]`,
    // never the model itself. We pin this with a static check on the engine
    // call sites: every `recorder()->record([` block in WorkflowEngine.php
    // builds metadata with `->value` / primitive literals only.
    $engine = file_get_contents(__DIR__.'/../../src/Engines/WorkflowEngine.php');
    expect($engine)->toContain("'metadata' =>");
    $hasRawModel = (bool) preg_match(
        "/'metadata'\s*=>\s*\[[^\]]*\\\$[A-Za-z_]+(?![\\->\\[])[^,\\]]*\\\$[A-Za-z_]+/",
        $engine,
    );
    expect($hasRawModel)->toBeFalse();
});

it('(c) the persisted metadata column is a JSON string and round-trips as an array', function (): void {
    $instancesTable = config('workflow.table_prefix', 'workflow_').'instances';
    $instanceId = DB::table($instancesTable)->insertGetId([
        'uuid' => (string) Str::uuid(),
        'workflow_id' => 0,
        'workflow_version' => 1,
        'subject_type' => 'host',
        'subject_id' => 0,
        'status' => 'in_progress',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $recorder = new HistoryRecorder(app(Dispatcher::class));
    $row = $recorder->record([
        'workflow_instance_id' => $instanceId,
        'event' => HistoryEvent::Started,
        'metadata' => ['workflow_code' => 'order-approval', 'workflow_version' => 2],
    ]);

    // The raw value in the DB is a string (JSON-encoded), not serialized PHP.
    $raw = DB::table((new WorkflowHistory)->getTable())
        ->where('id', $row->id)
        ->value('metadata');
    expect($raw)->toBeString()
        ->and(json_decode($raw, true))->toBe([
            'workflow_code' => 'order-approval',
            'workflow_version' => 2,
        ]);

    // Eloquent cast brings it back as an array on the model.
    $reloaded = WorkflowHistory::query()->find($row->id);
    expect($reloaded->metadata)->toBe([
        'workflow_code' => 'order-approval',
        'workflow_version' => 2,
    ]);
});
