<?php

declare(strict_types=1);

use App\Enums\ChecklistOutcome;
use App\Enums\MachineStatus;
use App\Models\ChecklistEntry;
use App\Models\Machine;
use App\Models\MachineNote;
use App\Models\Report;

use function Pest\Laravel\assertDatabaseCount;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

describe('POST /api/reports', function () {
    it('imports a report and creates the machine', function () {
        $user = apiUser(['write']);

        $response = postJson('/api/reports', ['markdown' => fixtureReport()])
            ->assertCreated()
            ->assertJsonStructure(['machine_id', 'report_id', 'created'])
            ->assertJsonPath('created', true);

        $machine = $user->machines()->sole();
        $report = Report::query()->sole();

        expect($response->json('machine_id'))->toBe($machine->id)
            ->and($response->json('report_id'))->toBe($report->id)
            ->and($report->machine_id)->toBe($machine->id)
            ->and($machine->os)->toBe('Windows')
            ->and($machine->device_type)->toBe('desktop');
    });

    it('answers 200 and adds nothing when the identical report already exists', function () {
        $user = apiUser(['write']);
        $first = postJson('/api/reports', ['markdown' => fixtureReport()])->assertCreated();

        postJson('/api/reports', ['markdown' => fixtureReport()])
            ->assertOk()
            ->assertExactJson(['machine_id' => $first->json('machine_id'), 'report_id' => $first->json('report_id'), 'created' => false]);

        postJson('/api/reports', ['markdown' => str_replace("\n", "\r\n", fixtureReport())])->assertOk();

        assertDatabaseCount('reports', 1);
        expect($user->machines()->count())->toBe(1);
    });

    it('attaches a changed report to the same machine', function () {
        $user = apiUser(['write']);
        postJson('/api/reports', ['markdown' => fixtureReport()])->assertCreated();

        postJson('/api/reports', ['markdown' => str_replace('Free disk: 17%', 'Free disk: 40%', fixtureReport())])
            ->assertCreated()
            ->assertJsonPath('created', true);

        assertDatabaseCount('reports', 2);
        expect($user->machines()->count())->toBe(1);
    });

    it('never attaches a report to another user’s machine', function () {
        $theirs = Machine::factory()->create(['identity_key' => 'uuid:4c4c4544-0053-5410-8058-b7c04f4b4e31']);
        $user = apiUser(['write']);

        postJson('/api/reports', ['markdown' => fixtureReport()])->assertCreated();

        expect($user->machines()->count())->toBe(1)
            ->and($theirs->reports()->count())->toBe(0)
            ->and(Machine::query()->count())->toBe(2);
    });

    it('rejects a report with only a placeholder serial and no uuid with the importer’s message', function () {
        apiUser(['write']);
        $markdown = str_replace(['- Serial:', '- Hardware UUID: 4C4C4544-0053-5410-8058-B7C04F4B4E31'], ['- Serial: To be filled by O.E.M.', '- Hardware UUID: 00000000-0000-0000-0000-000000000000'], fixtureReport());

        postJson('/api/reports', ['markdown' => $markdown])
            ->assertUnprocessable()
            ->assertJsonStructure(['message', 'errors' => ['report']])
            ->assertJsonPath('errors.report.0', 'The report has no usable serial number or hardware UUID, so it cannot be matched to a machine.');

        assertDatabaseCount('machines', 0);
        assertDatabaseCount('reports', 0);
    });

    it('rejects text that is not a report at all', function () {
        apiUser(['write']);

        postJson('/api/reports', ['markdown' => 'just some words'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('report');
    });

    it('rejects a missing, empty, whitespace only, oversized or non string markdown', function (mixed $body) {
        apiUser(['write']);

        postJson('/api/reports', $body instanceof Closure ? $body() : $body)
            ->assertUnprocessable()
            ->assertJsonStructure(['message', 'errors' => ['markdown']]);

        assertDatabaseCount('reports', 0);
    })->with([
        'missing' => [[]],
        'null' => [['markdown' => null]],
        'empty' => [['markdown' => '']],
        'whitespace' => [['markdown' => "  \n\t  "]],
        'array' => [['markdown' => ['x']]],
        'number' => [['markdown' => 5]],
        'oversized' => [fn () => ['markdown' => str_repeat('a', 2_000_001)]],
    ]);

    it('accepts the largest allowed markdown size on the validation side', function () {
        apiUser(['write']);

        postJson('/api/reports', ['markdown' => str_repeat('a', 2_000_000)])
            ->assertUnprocessable()
            ->assertJsonMissingValidationErrors('markdown');
    });
});

describe('PUT /api/machines/{machine}/status', function () {
    it('sets the status', function (string $status) {
        $user = apiUser(['write']);
        $machine = Machine::factory()->for($user)->create();

        putJson("/api/machines/{$machine->id}/status", ['status' => $status])
            ->assertOk()
            ->assertExactJson(['data' => ['id' => $machine->id, 'status' => $status]]);

        expect($machine->refresh()->status)->toBe(MachineStatus::from($status));
    })->with(['ok', 'needs_work', 'reject']);

    it('rejects a missing, unknown or non string status and changes nothing', function (mixed $status) {
        $user = apiUser(['write']);
        $machine = Machine::factory()->for($user)->create(['status' => MachineStatus::Ok]);

        putJson("/api/machines/{$machine->id}/status", ['status' => $status])
            ->assertUnprocessable()
            ->assertJsonStructure(['message', 'errors' => ['status']]);

        expect($machine->refresh()->status)->toBe(MachineStatus::Ok);
    })->with([null, '', 'great', 'OK', [['ok']], 1]);
});

describe('PUT /api/machines/{machine}/checklist/{item}', function () {
    it('records an outcome and a note', function () {
        $user = apiUser(['write']);
        $machine = Machine::factory()->for($user)->create();

        putJson("/api/machines/{$machine->id}/checklist/screen", ['outcome' => 'fail', 'note' => 'Dead pixel'])
            ->assertOk()
            ->assertJsonStructure(['data' => ['key', 'label', 'group', 'outcome', 'note']])
            ->assertExactJson(['data' => [
                'key' => 'screen',
                'label' => 'Screen has no dead pixels, bright spots or burn-in',
                'group' => 'common',
                'outcome' => 'fail',
                'note' => 'Dead pixel',
            ]]);

        assertDatabaseHas('checklist_entries', ['machine_id' => $machine->id, 'item_key' => 'screen', 'outcome' => 'fail', 'note' => 'Dead pixel']);
    });

    it('updates an existing entry rather than adding another', function () {
        $user = apiUser(['write']);
        $machine = Machine::factory()->for($user)->create();
        ChecklistEntry::factory()->for($machine)->create(['item_key' => 'screen', 'outcome' => ChecklistOutcome::Fail]);

        putJson("/api/machines/{$machine->id}/checklist/screen", ['outcome' => 'pass'])
            ->assertOk()
            ->assertJsonPath('data.outcome', 'pass')
            ->assertJsonPath('data.note', null);

        assertDatabaseCount('checklist_entries', 1);
    });

    it('clears an entry when the outcome is null', function () {
        $user = apiUser(['write']);
        $machine = Machine::factory()->for($user)->create();
        ChecklistEntry::factory()->for($machine)->create(['item_key' => 'screen', 'outcome' => ChecklistOutcome::Fail, 'note' => 'x']);

        putJson("/api/machines/{$machine->id}/checklist/screen", ['outcome' => null])
            ->assertOk()
            ->assertJsonPath('data.outcome', null)
            ->assertJsonPath('data.note', null);

        assertDatabaseCount('checklist_entries', 0);
    });

    it('answers 404 for an item the machine’s checklist does not have', function (string $item) {
        $user = apiUser(['write']);
        $machine = Machine::factory()->for($user)->create(['os' => 'macOS', 'device_type' => 'laptop']);

        putJson("/api/machines/{$machine->id}/checklist/{$item}", ['outcome' => 'pass'])->assertNotFound();

        assertDatabaseCount('checklist_entries', 0);
    })->with(['nonsense', 'bitlocker', 'fans', 'Screen', 'a-b']);

    it('rejects an unknown outcome or an oversized note', function (array $body) {
        $user = apiUser(['write']);
        $machine = Machine::factory()->for($user)->create();

        putJson("/api/machines/{$machine->id}/checklist/screen", $body)->assertUnprocessable();

        assertDatabaseCount('checklist_entries', 0);
    })->with([
        'unknown outcome' => [['outcome' => 'maybe']],
        'wrong case' => [['outcome' => 'Pass']],
        'array outcome' => [['outcome' => ['pass']]],
        'note too long' => [['outcome' => 'pass', 'note' => str_repeat('a', 1001)]],
        'note not text' => [['outcome' => 'pass', 'note' => ['x']]],
    ]);

    it('accepts a note of exactly 1000 characters', function () {
        $user = apiUser(['write']);
        $machine = Machine::factory()->for($user)->create();

        putJson("/api/machines/{$machine->id}/checklist/screen", ['outcome' => 'pass', 'note' => str_repeat('a', 1000)])->assertOk();
    });
});

describe('POST /api/machines/{machine}/notes', function () {
    it('adds a note and returns it', function () {
        $user = apiUser(['write']);
        $machine = Machine::factory()->for($user)->create();

        $response = postJson("/api/machines/{$machine->id}/notes", ['body' => 'Fan is loud under load.'])
            ->assertCreated()
            ->assertJsonStructure(['data' => ['id', 'body', 'created_at']])
            ->assertJsonPath('data.body', 'Fan is loud under load.');

        $note = MachineNote::query()->sole();

        expect($response->json('data.id'))->toBe($note->id)
            ->and($note->machine_id)->toBe($machine->id);
    });

    it('keeps markup as plain text', function () {
        $user = apiUser(['write']);
        $machine = Machine::factory()->for($user)->create();

        postJson("/api/machines/{$machine->id}/notes", ['body' => '<script>alert(1)</script>'])
            ->assertCreated()
            ->assertJsonPath('data.body', '<script>alert(1)</script>');
    });

    it('rejects a missing, empty, whitespace only, oversized or non string body', function (mixed $body) {
        $user = apiUser(['write']);
        $machine = Machine::factory()->for($user)->create();

        postJson("/api/machines/{$machine->id}/notes", ['body' => $body])
            ->assertUnprocessable()
            ->assertJsonStructure(['message', 'errors' => ['body']]);

        assertDatabaseCount('machine_notes', 0);
    })->with([
        'null' => [null],
        'empty' => [''],
        'whitespace' => ["  \n\t "],
        'too long' => [fn () => str_repeat('a', 5001)],
        'array' => [['x']],
    ]);

    it('accepts a note of exactly 5000 characters', function () {
        $user = apiUser(['write']);
        $machine = Machine::factory()->for($user)->create();

        postJson("/api/machines/{$machine->id}/notes", ['body' => str_repeat('a', 5000)])->assertCreated();
    });
});
