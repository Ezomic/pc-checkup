<?php

declare(strict_types=1);

use App\Enums\ChecklistOutcome;
use App\Enums\MachineStatus;
use App\Models\ChecklistEntry;
use App\Models\Machine;
use App\Models\MachineNote;
use App\Models\Photo;
use App\Models\Report;

use function Pest\Laravel\getJson;

const MACHINE_FIELDS = ['id', 'name', 'serial', 'hardware_uuid', 'os', 'device_type', 'model', 'status'];

describe('GET /api/machines', function () {
    it('lists the machines with the same fields as the UI plus counts', function () {
        $user = apiUser(['read']);
        $machine = Machine::factory()->for($user)->create([
            'name' => 'Desk-PC',
            'serial' => 'SER123',
            'status' => MachineStatus::Ok,
        ]);
        Report::factory()->for($machine)->create(['created_at' => '2026-10-01 10:00:00']);
        Report::factory()->for($machine)->create(['created_at' => '2026-10-05 10:00:00']);

        getJson('/api/machines')
            ->assertOk()
            ->assertJsonStructure([
                'data' => ['*' => [...MACHINE_FIELDS, 'reports_count', 'last_report_at', 'created_at']],
                'links' => ['first', 'last', 'prev', 'next'],
                'meta' => ['current_page', 'from', 'last_page', 'path', 'per_page', 'to', 'total'],
            ])
            ->assertJsonPath('data.0.id', $machine->id)
            ->assertJsonPath('data.0.name', 'Desk-PC')
            ->assertJsonPath('data.0.serial', 'SER123')
            ->assertJsonPath('data.0.status', 'ok')
            ->assertJsonPath('data.0.reports_count', 2)
            ->assertJsonPath('data.0.last_report_at', '2026-10-05T10:00:00.000000Z')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.per_page', 15);
    });

    it('shows a machine without reports as zero reports and no last report', function () {
        $user = apiUser(['read']);
        Machine::factory()->for($user)->create();

        getJson('/api/machines')
            ->assertJsonPath('data.0.reports_count', 0)
            ->assertJsonPath('data.0.last_report_at', null);
    });

    it('never lists another user’s or a deleted machine', function () {
        $user = apiUser(['read']);
        $mine = Machine::factory()->for($user)->create();
        Machine::factory()->for($user)->create()->delete();
        $theirs = Machine::factory()->create(['serial' => 'THEIRS-SERIAL']);
        Report::factory()->for($theirs)->create();

        $response = getJson('/api/machines')->assertOk();

        expect(collect($response->json('data'))->pluck('id')->all())->toBe([$mine->id])
            ->and($response->json('meta.total'))->toBe(1)
            ->and($response->getContent())->not->toContain('THEIRS-SERIAL');
    });

    it('leaks neither report text nor internal columns', function () {
        $user = apiUser(['read']);
        $machine = Machine::factory()->for($user)->create();
        Report::factory()->for($machine)->create(['markdown' => "# SECRET-REPORT-BODY\n"]);

        $response = getJson('/api/machines')->assertOk();

        expect($response->getContent())->not->toContain('SECRET-REPORT-BODY')
            ->and($response->json('data.0'))->not->toHaveKeys(['markdown', 'identity_key', 'user_id', 'deleted_at', 'updated_at']);
    });

    it('paginates newest first', function () {
        $user = apiUser(['read']);
        Machine::factory()->count(17)->for($user)->create();

        getJson('/api/machines')->assertJsonCount(15, 'data')->assertJsonPath('meta.last_page', 2);
        getJson('/api/machines?page=2')->assertJsonCount(2, 'data')->assertJsonPath('meta.current_page', 2);
    });
});

describe('GET /api/machines/{machine}', function () {
    it('returns the machine, latest report, resolved checklist, notes and photo metadata', function () {
        $user = apiUser(['read']);
        $machine = Machine::factory()->for($user)->create([
            'name' => 'Desk-PC',
            'os' => 'Windows',
            'device_type' => 'desktop',
            'status' => MachineStatus::Reject,
        ]);
        Report::factory()->for($machine)->create(['created_at' => '2026-10-01 10:00:00', 'markdown' => "# Old\n"]);
        $latest = Report::factory()->for($machine)->create([
            'created_at' => '2026-10-05 10:00:00',
            'markdown' => "# Newest\n",
            'flags' => ['Secure Boot is OFF.'],
        ]);
        ChecklistEntry::factory()->for($machine)->create(['item_key' => 'screen', 'outcome' => ChecklistOutcome::Fail, 'note' => 'Dead pixel']);
        $note = MachineNote::factory()->for($machine)->create(['body' => 'Fan is loud.']);
        $photo = Photo::factory()->for($machine)->create(['caption' => 'Front', 'width' => 800, 'height' => 600, 'bytes' => 1234]);

        $response = getJson("/api/machines/{$machine->id}")
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    ...MACHINE_FIELDS,
                    'latest_report' => ['id', 'created_at', 'flags', 'markdown'],
                    'checklist' => ['*' => ['key', 'label', 'group', 'outcome', 'note']],
                    'notes' => ['*' => ['id', 'body', 'created_at']],
                    'photos' => ['*' => ['id', 'caption', 'width', 'height', 'bytes', 'created_at']],
                ],
            ])
            ->assertJsonPath('data.id', $machine->id)
            ->assertJsonPath('data.status', 'reject')
            ->assertJsonPath('data.latest_report.id', $latest->id)
            ->assertJsonPath('data.latest_report.markdown', "# Newest\n")
            ->assertJsonPath('data.latest_report.flags', ['Secure Boot is OFF.'])
            ->assertJsonPath('data.notes.0.id', $note->id)
            ->assertJsonPath('data.notes.0.body', 'Fan is loud.')
            ->assertJsonPath('data.photos.0.id', $photo->id)
            ->assertJsonPath('data.photos.0.caption', 'Front')
            ->assertJsonPath('data.photos.0.bytes', 1234);

        $screen = collect($response->json('data.checklist'))->firstWhere('key', 'screen');
        $ports = collect($response->json('data.checklist'))->firstWhere('key', 'ports');

        expect($screen)->toBe(['key' => 'screen', 'label' => 'Screen has no dead pixels, bright spots or burn-in', 'group' => 'common', 'outcome' => 'fail', 'note' => 'Dead pixel'])
            ->and($ports['outcome'])->toBeNull()
            ->and($ports['note'])->toBeNull()
            ->and(collect($response->json('data.checklist'))->pluck('group')->unique()->values()->all())->toBe(['common', 'desktop', 'windows']);
    });

    it('returns no report when there is none', function () {
        $user = apiUser(['read']);
        $machine = Machine::factory()->for($user)->create();

        getJson("/api/machines/{$machine->id}")
            ->assertOk()
            ->assertJsonPath('data.latest_report', null)
            ->assertJsonPath('data.notes', [])
            ->assertJsonPath('data.photos', []);
    });

    it('never exposes image bytes, storage paths or URLs for photos', function () {
        $user = apiUser(['read']);
        $machine = Machine::factory()->for($user)->create();
        Photo::factory()->for($machine)->create(['path' => 'photos/secret-location.jpg']);

        $response = getJson("/api/machines/{$machine->id}")->assertOk();

        expect($response->json('data.photos.0'))->not->toHaveKeys(['path', 'url'])
            ->and($response->getContent())->not->toContain('secret-location')
            ->and($response->getContent())->not->toContain('/photos/');
    });

    it('does not leak another machine’s notes, photos or reports', function () {
        $user = apiUser(['read']);
        $machine = Machine::factory()->for($user)->create();
        $other = Machine::factory()->for($user)->create();
        MachineNote::factory()->for($other)->create(['body' => 'OTHER-NOTE']);
        Photo::factory()->for($other)->create();
        Report::factory()->for($other)->create(['markdown' => "# OTHER-REPORT\n"]);

        $response = getJson("/api/machines/{$machine->id}")->assertOk();

        expect($response->json('data.notes'))->toBe([])
            ->and($response->json('data.photos'))->toBe([])
            ->and($response->json('data.latest_report'))->toBeNull()
            ->and($response->getContent())->not->toContain('OTHER-');
    });
});
