<?php

declare(strict_types=1);

use App\Models\Machine;
use App\Models\Report;
use App\Models\ScanCode;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseCount;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\post;

function startedCode(): string
{
    post(route('scans.store'))->assertRedirect(route('scans.show'));

    $code = null;
    get(route('scans.show'))->assertInertia(function (Assert $page) use (&$code) {
        $code = $page->toArray()['props']['code'];
    });

    return (string) $code;
}

describe('starting a scan', function () {
    it('sends guests to the login', function () {
        post(route('scans.store'))->assertRedirect(route('login'));
        get(route('scans.show'))->assertRedirect(route('login'));
        getJson(route('scans.status'))->assertUnauthorized();
    });

    it('shows a six digit code once, valid for fifteen minutes', function () {
        $this->freezeTime();
        actingAs(User::factory()->create());

        post(route('scans.store'))->assertRedirect(route('scans.show'));

        get(route('scans.show'))->assertInertia(fn (Assert $page) => $page
            ->component('scans/Show')
            ->where('code', fn (string $code) => preg_match('/^\d{6}$/', $code) === 1)
            ->where('expiresAt', now()->addMinutes(15)->toIso8601String()));

        get(route('scans.show'))->assertInertia(fn (Assert $page) => $page
            ->where('code', null)
            ->where('expiresAt', now()->addMinutes(15)->toIso8601String()));
    });

    it('stores only a keyed hash of the code', function () {
        actingAs($user = User::factory()->create());
        post(route('scans.store'));

        $scan = ScanCode::query()->sole();
        $code = (string) session('scan_code');

        expect($code)->toMatch('/^\d{6}$/')
            ->and($scan->user_id)->toBe($user->id)
            ->and($scan->code_hash)->toBe(ScanCode::hashCode($code))
            ->and($scan->code_hash)->not->toBe(hash('sha256', $code))
            ->and(json_encode($scan->getAttributes()))->not->toContain($code);
    });

    it('keeps one code per user and replaces the old one', function () {
        actingAs($user = User::factory()->create());
        $other = ScanCode::factory()->withCode('777777')->create();

        startedCode();
        $second = startedCode();

        expect($user->scanCode()->count())->toBe(1)
            ->and($user->scanCode()->sole()->code_hash)->toBe(ScanCode::hashCode($second))
            ->and($other->refresh()->used_at)->toBeNull();
        assertDatabaseCount('scan_codes', 2);
    });

    it('removes codes that expired long ago', function () {
        ScanCode::factory()->expired()->create(['expires_at' => now()->subDays(3)]);
        actingAs(User::factory()->create());

        post(route('scans.store'));

        assertDatabaseCount('scan_codes', 1);
    });
});

describe('waiting for the report', function () {
    it('reports nothing before a code is started', function () {
        actingAs(User::factory()->create());

        getJson(route('scans.status'))->assertOk()->assertExactJson(['status' => 'none', 'machine_id' => null]);
    });

    it('reports waiting while the code is live', function () {
        actingAs(User::factory()->create());
        post(route('scans.store'));

        getJson(route('scans.status'))->assertOk()->assertExactJson(['status' => 'waiting', 'machine_id' => null]);
    });

    it('reports the machine once the report arrived', function () {
        actingAs($user = User::factory()->create());
        $machine = Machine::factory()->for($user)->create();
        $report = Report::factory()->for($machine)->create();
        ScanCode::factory()->for($user)->used($report)->create();

        getJson(route('scans.status'))->assertOk()->assertExactJson(['status' => 'received', 'machine_id' => $machine->id]);
    });

    it('reports expired after fifteen minutes', function () {
        actingAs(User::factory()->create());
        post(route('scans.store'));

        $this->travel(16)->minutes();

        getJson(route('scans.status'))->assertOk()->assertExactJson(['status' => 'expired', 'machine_id' => null]);
    });

    it('reports expired when the report behind a used code is gone', function () {
        actingAs($user = User::factory()->create());
        ScanCode::factory()->for($user)->used()->create();

        getJson(route('scans.status'))->assertOk()->assertExactJson(['status' => 'expired', 'machine_id' => null]);
    });

    it('never shows another account the scan', function () {
        $other = User::factory()->create();
        $machine = Machine::factory()->for($other)->create();
        ScanCode::factory()->for($other)->used(Report::factory()->for($machine)->create())->create();

        actingAs(User::factory()->create());

        getJson(route('scans.status'))->assertOk()->assertExactJson(['status' => 'none', 'machine_id' => null]);
    });
});
