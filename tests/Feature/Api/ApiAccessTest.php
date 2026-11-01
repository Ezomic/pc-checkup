<?php

declare(strict_types=1);

use App\Actions\ApiTokens\CreateApiToken;
use App\Enums\ApiAbility;
use App\Enums\MachineStatus;
use App\Models\ChecklistEntry;
use App\Models\Machine;
use App\Models\MachineNote;
use App\Models\Report;
use App\Models\User;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\assertDatabaseCount;

const READ_ENDPOINTS = [
    'list' => ['GET', '/api/machines', []],
    'show' => ['GET', '/api/machines/{id}', []],
];

const WRITE_ENDPOINTS = [
    'import a report' => ['POST', '/api/reports', ['markdown' => 'x']],
    'set the status' => ['PUT', '/api/machines/{id}/status', ['status' => 'ok']],
    'record a checklist entry' => ['PUT', '/api/machines/{id}/checklist/screen', ['outcome' => 'pass', 'note' => 'fine']],
    'add a note' => ['POST', '/api/machines/{id}/notes', ['body' => 'hello']],
];

const MACHINE_ENDPOINTS = [
    'show' => ['GET', '/api/machines/{id}', []],
    'set the status' => ['PUT', '/api/machines/{id}/status', ['status' => 'ok']],
    'record a checklist entry' => ['PUT', '/api/machines/{id}/checklist/screen', ['outcome' => 'pass', 'note' => 'fine']],
    'add a note' => ['POST', '/api/machines/{id}/notes', ['body' => 'hello']],
];

/**
 * @param  array<string, mixed>  $body
 */
function callApi(string $method, string $uri, array $body = [], ?int $machineId = null): TestResponse
{
    return test()->json($method, str_replace('{id}', (string) ($machineId ?? 999999), $uri), $body);
}

function assertNothingChanged(Machine $machine): void
{
    assertDatabaseCount('reports', 0);
    assertDatabaseCount('machine_notes', 0);
    assertDatabaseCount('checklist_entries', 0);
    expect($machine->refresh()->status)->toBe(MachineStatus::NeedsWork);
}

describe('authentication', function () {
    it('answers every endpoint with a JSON 401 when there is no token', function (string $method, string $uri, array $body) {
        $machine = Machine::factory()->create();

        callApi($method, $uri, $body, $machine->id)
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);

        assertNothingChanged($machine);
    })->with([...READ_ENDPOINTS, ...WRITE_ENDPOINTS]);

    it('refuses a browser session, which carries no token and so no ability', function (string $method, string $uri, array $body) {
        $user = User::factory()->create();
        $machine = Machine::factory()->for($user)->create();

        test()->actingAs($user);
        callApi($method, $uri, $body, $machine->id)->assertUnauthorized();

        assertNothingChanged($machine);
    })->with([...READ_ENDPOINTS, ...WRITE_ENDPOINTS]);

    it('refuses a token that was never issued', function () {
        test()->withToken('1|totallyMadeUpTokenValue')->getJson('/api/machines')->assertUnauthorized();
    });

    it('refuses an expired token', function () {
        $plain = app(CreateApiToken::class)->handle(User::factory()->create(), 'CLI', [ApiAbility::Read]);

        test()->travel(90)->days();
        test()->travel(-1)->minutes();
        test()->withToken($plain)->getJson('/api/machines')->assertOk();

        app('auth')->forgetGuards();
        test()->travel(2)->minutes();
        test()->withToken($plain)->getJson('/api/machines')->assertUnauthorized();
    });

    it('refuses a revoked token', function () {
        $user = User::factory()->create();
        $plain = app(CreateApiToken::class)->handle($user, 'CLI', [ApiAbility::Read]);

        test()->withToken($plain)->getJson('/api/machines')->assertOk();

        $user->tokens()->delete();
        app('auth')->forgetGuards();

        test()->withToken($plain)->getJson('/api/machines')->assertUnauthorized();
    });

    it('still throttles the API at 60 requests a minute per token', function () {
        $plain = app(CreateApiToken::class)->handle(User::factory()->create(), 'CLI', [ApiAbility::Read]);

        foreach (range(1, 60) as $ignored) {
            test()->withToken($plain)->getJson('/api/machines')->assertOk();
        }

        test()->withToken($plain)->getJson('/api/machines')->assertTooManyRequests();
    });
});

describe('abilities', function () {
    it('gives a read only token a 403 on every write endpoint and changes nothing', function (string $method, string $uri, array $body) {
        $user = apiUser(['read']);
        $machine = Machine::factory()->for($user)->create();

        callApi($method, $uri, $body, $machine->id)
            ->assertForbidden()
            ->assertJsonPath('message', 'Invalid ability provided.');

        assertNothingChanged($machine);
    })->with(WRITE_ENDPOINTS);

    it('gives a write only token a 403 on every read endpoint', function (string $method, string $uri) {
        $user = apiUser(['write']);
        $machine = Machine::factory()->for($user)->create();

        callApi($method, $uri, [], $machine->id)->assertForbidden();
    })->with(READ_ENDPOINTS);

    it('does not let a weak token tell a real machine from a missing or foreign one', function (string $method, string $uri, array $body) {
        apiUser(['read']);
        $foreign = Machine::factory()->create();

        callApi($method, $uri, $body, $foreign->id)->assertForbidden();
        callApi($method, $uri, $body)->assertForbidden();
    })->with(['status' => WRITE_ENDPOINTS['set the status'], 'note' => WRITE_ENDPOINTS['add a note'], 'checklist' => WRITE_ENDPOINTS['record a checklist entry']]);

    it('treats a token holding every ability as full access', function () {
        $user = User::factory()->create();
        $machine = Machine::factory()->for($user)->create();
        $plain = $user->createToken('Old token')->plainTextToken;

        expect($user->tokens()->sole()->abilities)->toBe(['*']);

        test()->withToken($plain)->getJson("/api/machines/{$machine->id}")->assertOk();
        app('auth')->forgetGuards();
        test()->withToken($plain)->putJson("/api/machines/{$machine->id}/status", ['status' => 'ok'])->assertOk();
    });

    it('lets a token holding both abilities read and write', function () {
        $user = User::factory()->create();
        $machine = Machine::factory()->for($user)->create();
        $plain = app(CreateApiToken::class)->handle($user, 'Both', [ApiAbility::Read, ApiAbility::Write]);

        test()->withToken($plain)->getJson("/api/machines/{$machine->id}")->assertOk();
        app('auth')->forgetGuards();
        test()->withToken($plain)->postJson("/api/machines/{$machine->id}/notes", ['body' => 'hi'])->assertCreated();
    });

    it('uses a real read only token the same way as the fake one', function () {
        $user = User::factory()->create();
        $machine = Machine::factory()->for($user)->create();
        $plain = app(CreateApiToken::class)->handle($user, 'Read', [ApiAbility::Read]);

        test()->withToken($plain)->postJson("/api/machines/{$machine->id}/notes", ['body' => 'hi'])->assertForbidden();
        assertDatabaseCount('machine_notes', 0);
    });
});

describe('ownership', function () {
    it('answers 404 for another user’s machine on every machine endpoint and changes nothing', function (string $method, string $uri, array $body) {
        apiUser();
        $theirs = Machine::factory()->create(['status' => MachineStatus::NeedsWork]);
        Report::factory()->for($theirs)->create();
        $reports = Report::query()->count();

        callApi($method, $uri, $body, $theirs->id)->assertNotFound();

        expect(Report::query()->count())->toBe($reports)
            ->and(MachineNote::query()->count())->toBe(0)
            ->and(ChecklistEntry::query()->count())->toBe(0)
            ->and($theirs->refresh()->status)->toBe(MachineStatus::NeedsWork);
    })->with(MACHINE_ENDPOINTS);

    it('answers 404 for a soft deleted machine on every machine endpoint', function (string $method, string $uri, array $body) {
        $user = apiUser();
        $machine = Machine::factory()->for($user)->create();
        $machine->delete();

        callApi($method, $uri, $body, $machine->id)->assertNotFound();

        expect(MachineNote::query()->count())->toBe(0)
            ->and(ChecklistEntry::query()->count())->toBe(0)
            ->and(Machine::withTrashed()->sole()->status)->toBe(MachineStatus::NeedsWork);
    })->with(MACHINE_ENDPOINTS);

    it('answers 404 for a machine that does not exist', function (string $method, string $uri, array $body) {
        apiUser();

        callApi($method, $uri, $body)->assertNotFound()->assertJsonStructure(['message']);
    })->with(MACHINE_ENDPOINTS);

    it('answers 404 for a machine id that is not a number', function () {
        apiUser();

        test()->getJson('/api/machines/abc')->assertNotFound();
    });

    it('gives a foreign and a missing machine the same answer', function () {
        apiUser();
        $theirs = Machine::factory()->create();

        $foreign = test()->getJson("/api/machines/{$theirs->id}");
        $missing = test()->getJson('/api/machines/999999');

        expect($foreign->status())->toBe($missing->status())
            ->and($foreign->json('message'))->toBe($missing->json('message'));
    });
});
