<?php

declare(strict_types=1);

use App\Actions\Scans\ApprovePairing;
use App\Actions\Scans\RejectPairing;
use App\Enums\PairingStatus;
use App\Models\Client;
use App\Models\Machine;
use App\Models\Report;
use App\Models\ScanCode;
use App\Models\ScanPairing;
use App\Models\User;
use App\Services\Scans\PairingQr;
use App\Services\Scans\PairingThrottle;
use App\Services\Scans\ScanCodeThrottle;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseCount;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

/**
 * Asks for a pairing the way a script does: no login, no cookie, nothing about the machine.
 *
 * @return array{id: string, secret: string, check_code: string, url: string, qr: list<string>, expires_in: int}
 */
function spStart(string $query = '', string $ip = '203.0.113.10'): array
{
    /** @var array{id: string, secret: string, check_code: string, url: string, qr: list<string>, expires_in: int} */
    return test()->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/scripts/pair'.$query)->assertCreated()->json();
}

function spPoll(string $id, ?string $secret, string $ip = '203.0.113.10'): TestResponse
{
    return test()->withServerVariables(['REMOTE_ADDR' => $ip])
        ->getJson('/scripts/pair/'.rawurlencode($id), $secret === null ? [] : ['Authorization' => 'Bearer '.$secret]);
}

function spUpload(mixed $token, string $markdown, string $ip = '203.0.113.10'): TestResponse
{
    return test()->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/api/scans', ['upload_token' => $token, 'markdown' => $markdown]);
}

/**
 * @param  array<string, mixed>  $choice
 */
function spApprove(User $user, string $id, array $choice): TestResponse
{
    return actingAs($user)->post(route('pairings.approve', ['pairing' => $id]), $choice);
}

/**
 * Starts a pairing, has $user approve it into $client (and $machine), and collects the token.
 */
function spApprovedToken(User $user, Client $client, ?Machine $machine = null): string
{
    $pairing = spStart();
    spApprove($user, $pairing['id'], ['client_id' => $client->id, 'machine_id' => $machine?->id])->assertRedirect();

    return (string) spPoll($pairing['id'], $pairing['secret'])->assertOk()->json('upload_token');
}

/**
 * The identity ImportReport gives the Windows desktop fixture.
 */
function spDesktopMachine(User $user, ?Client $client = null): Machine
{
    return Machine::factory()->for($user)->create([
        'identity_key' => 'uuid:4c4c4544-0053-5410-8058-b7c04f4b4e31',
        'serial' => null,
        'hardware_uuid' => '4C4C4544-0053-5410-8058-B7C04F4B4E31',
        'client_id' => $client?->id,
    ]);
}

describe('starting a pairing', function () {
    it('needs no login and answers with an id, a secret, a check code, the approve link and its QR code', function () {
        $response = test()->postJson('/scripts/pair')->assertCreated();
        $data = $response->json();

        expect(array_keys($data))->toBe(['id', 'secret', 'check_code', 'url', 'qr', 'expires_in'])
            ->and($data['id'])->toMatch('/\A[A-Za-z0-9]{24}\z/')
            ->and($data['secret'])->toMatch('/\A[A-Za-z0-9]{40}\z/')
            ->and($data['check_code'])->toMatch('/\A[ACDEFHJKMNPRTUVWXY34679]{4}\z/')
            ->and($data['url'])->toBe(rtrim(config()->string('app.url'), '/').'/p/'.$data['id'])
            ->and($data['expires_in'])->toBe(600)
            ->and($data['qr'])->toBe((new PairingQr)->lines($data['url']))
            ->and($response->headers->get('Cache-Control'))->toContain('no-store');
    });

    it('answers in plain ASCII, so a script that guesses the wrong charset still reads the QR code', function () {
        $body = (string) test()->postJson('/scripts/pair')->getContent();

        $escapedFullBlock = substr((string) json_encode("\u{2588}"), 1, -1);

        expect($body)->toMatch('/\A[\x20-\x7e]+\z/')->toContain($escapedFullBlock);
    });

    it('draws the QR code in ASCII when the script asks for it', function () {
        $data = spStart('?qr=ascii');

        expect($data['qr'])->toBe((new PairingQr)->lines($data['url'], ascii: true))
            ->and(implode('', $data['qr']))->toMatch('/\A[# ]+\z/');
    });

    it('falls back to half blocks for any other format', function (string $query) {
        $data = spStart($query);

        expect($data['qr'])->toBe((new PairingQr)->lines($data['url']));
    })->with(['unknown' => ['?qr=svg'], 'array' => ['?qr[]=ascii'], 'upper case' => ['?qr=ASCII'], 'empty' => ['?qr=']]);

    it('builds the link from APP_URL, not from the Host header', function () {
        $data = test()->withHeader('Host', 'evil.test')->postJson('/scripts/pair')->assertCreated()->json();

        expect($data['url'])->toStartWith(rtrim(config()->string('app.url'), '/').'/p/')->not->toContain('evil.test');
    });

    it('stores only hashes and nothing about the computer or its address', function () {
        $data = spStart();
        $row = ScanPairing::query()->sole();
        $stored = (string) json_encode($row->getAttributes());

        expect($row->pairing_hash)->toBe(hash('sha256', $data['id']))
            ->and($row->secret_hash)->toBe(hash('sha256', $data['secret']))
            ->and($row->check_code)->toBe($data['check_code'])
            ->and($row->user_id)->toBeNull()
            ->and($row->client_id)->toBeNull()
            ->and($row->machine_id)->toBeNull()
            ->and($stored)->not->toContain($data['id'])->not->toContain($data['secret'])->not->toContain('203.0.113.10');
    });

    it('never hands out the same id or secret twice', function () {
        $first = spStart(ip: '203.0.113.1');
        $second = spStart(ip: '203.0.113.2');

        expect($first['id'])->not->toBe($second['id'])->and($first['secret'])->not->toBe($second['secret']);
    });

    it('is rate limited per address', function () {
        foreach (range(1, 5) as $ignored) {
            spStart(ip: '203.0.113.20');
        }

        test()->withServerVariables(['REMOTE_ADDR' => '203.0.113.20'])->postJson('/scripts/pair')->assertTooManyRequests();
        spStart(ip: '203.0.113.21');
    });

    it('prunes used and expired pairings when a new one starts, and keeps those still in use', function () {
        $user = User::factory()->create();
        $client = Client::factory()->for($user)->create();
        $kept = [
            ScanPairing::factory()->create()->id,
            ScanPairing::factory()->rejected($user)->create()->id,
            ScanPairing::factory()->approved($user, $client)->create(['expires_at' => now()->subMinutes(5)])->id,
        ];
        ScanPairing::factory()->expired()->create();
        ScanPairing::factory()->rejected($user)->expired()->create();
        ScanPairing::factory()->approved($user, $client)->used()->create();
        ScanPairing::factory()->approved($user, $client)->create(['expires_at' => now()->subMinutes(40), 'token_expires_at' => now()->subMinutes(10)]);

        spStart();

        expect(ScanPairing::query()->whereIn('id', $kept)->count())->toBe(3);
        assertDatabaseCount('scan_pairings', 4);
    });
});

describe('polling for the approval', function () {
    it('says only pending before anyone approves, even after the owner opened the page', function () {
        $pairing = spStart();
        $owner = User::factory()->create();
        Client::factory()->for($owner)->create(['name' => 'Secret Client']);
        actingAs($owner)->get(route('pairings.show', ['pairing' => $pairing['id']]))->assertOk();
        auth()->logout();

        $response = spPoll($pairing['id'], $pairing['secret'])->assertOk()->assertExactJson(['status' => 'pending']);

        expect($response->headers->get('Cache-Control'))->toContain('no-store')
            ->and((string) $response->getContent())->not->toContain($pairing['check_code']);
    });

    it('answers the same 404 for an unknown id, a wrong or missing secret, and nonsense', function (Closure $request) {
        $pairing = spStart();
        $other = spStart(ip: '203.0.113.11');

        /** @var array{0: string, 1: string|null} $args */
        $args = $request($pairing, $other);

        spPoll(...$args)->assertNotFound()->assertExactJson(['status' => 'unknown']);
    })->with([
        'unknown id' => [fn (array $p) => [Str::random(24), $p['secret']]],
        'wrong secret' => [fn (array $p) => [$p['id'], Str::random(40)]],
        'no secret' => [fn (array $p) => [$p['id'], null]],
        'empty secret' => [fn (array $p) => [$p['id'], '']],
        "another pairing's secret" => [fn (array $p, array $o) => [$p['id'], $o['secret']]],
        "another pairing's id" => [fn (array $p, array $o) => [$o['id'], $p['secret']]],
        'short id' => [fn (array $p) => [substr($p['id'], 0, 23), $p['secret']]],
        'long id' => [fn (array $p) => [$p['id'].'A', $p['secret']]],
        'unicode id' => [fn (array $p) => [str_repeat('é', 24), $p['secret']]],
        'control characters' => [fn (array $p) => ["\x01".substr($p['id'], 1), $p['secret']]],
        'secret with a space' => [fn (array $p) => [$p['id'], $p['secret'].' x']],
    ]);

    it('does not take the secret from the query string', function () {
        $pairing = spStart();

        test()->getJson('/scripts/pair/'.$pairing['id'].'?secret='.$pairing['secret'])->assertNotFound();
    });

    it('rate limits guessing per address, without locking out anyone else', function () {
        $pairing = spStart();

        foreach (range(1, PairingThrottle::ADDRESS_FAILURES) as $ignored) {
            spPoll(Str::random(24), Str::random(40), '198.51.100.7')->assertNotFound();
        }

        spPoll($pairing['id'], $pairing['secret'], '198.51.100.7')->assertTooManyRequests()->assertHeader('Retry-After');
        spPoll($pairing['id'], $pairing['secret'], '198.51.100.8')->assertOk()->assertExactJson(['status' => 'pending']);
    });

    it('does not count a good poll as a failure', function () {
        $pairing = spStart();

        foreach (range(1, PairingThrottle::ADDRESS_FAILURES + 2) as $ignored) {
            spPoll($pairing['id'], $pairing['secret'])->assertOk();
        }
    });

    it('hands the script a one-time upload token once the owner approved', function () {
        $user = User::factory()->create();
        $client = Client::factory()->for($user)->create();
        $pairing = spStart();
        spApprove($user, $pairing['id'], ['client_id' => $client->id])->assertRedirect();

        $data = spPoll($pairing['id'], $pairing['secret'])->assertOk()->json();

        expect(array_keys($data))->toBe(['status', 'upload_token', 'expires_in'])
            ->and($data['status'])->toBe('approved')
            ->and($data['upload_token'])->toMatch('/\A[A-Za-z0-9]{40}\z/')
            ->and($data['expires_in'])->toBeGreaterThan(1790)->toBeLessThanOrEqual(1800)
            ->and(ScanPairing::query()->sole()->token_hash)->toBe(hash('sha256', $data['upload_token']));
    });

    it('replaces the token on every poll, so only the newest one works', function () {
        $user = User::factory()->create();
        $client = Client::factory()->for($user)->create();
        $pairing = spStart();
        spApprove($user, $pairing['id'], ['client_id' => $client->id]);

        $first = (string) spPoll($pairing['id'], $pairing['secret'])->json('upload_token');
        $second = (string) spPoll($pairing['id'], $pairing['secret'])->json('upload_token');

        expect($first)->not->toBe($second);
        spUpload($first, fixtureReport())->assertUnprocessable();
        spUpload($second, fixtureReport())->assertCreated();
    });

    it('reports rejected, expired and used without anything else', function () {
        $user = User::factory()->create();
        $client = Client::factory()->for($user)->create();

        $rejected = spStart();
        actingAs($user)->post(route('pairings.reject', ['pairing' => $rejected['id']]))->assertRedirect();
        spPoll($rejected['id'], $rejected['secret'])->assertOk()->assertExactJson(['status' => 'rejected']);

        $used = spStart(ip: '203.0.113.12');
        spApprove($user, $used['id'], ['client_id' => $client->id]);
        spUpload(spPoll($used['id'], $used['secret'])->json('upload_token'), fixtureReport())->assertCreated();
        spPoll($used['id'], $used['secret'])->assertOk()->assertExactJson(['status' => 'used']);

        $expired = spStart(ip: '203.0.113.13');
        $this->travel(11)->minutes();
        spPoll($expired['id'], $expired['secret'])->assertOk()->assertExactJson(['status' => 'expired']);
    });

    it('reports expired once the upload window of an approval has passed', function () {
        $user = User::factory()->create();
        $pairing = spStart();
        spApprove($user, $pairing['id'], ['client_id' => Client::factory()->for($user)->create()->id]);

        $this->travel(31)->minutes();

        spPoll($pairing['id'], $pairing['secret'])->assertOk()->assertExactJson(['status' => 'expired']);
    });
});

describe('the approve page', function () {
    it('sends guests to the login and brings them back to the page afterwards', function () {
        $pairing = spStart();
        $url = route('pairings.show', ['pairing' => $pairing['id']]);
        $user = User::factory()->create();

        get($url)->assertRedirect(route('login'));
        post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertRedirect($url);
    });

    it('shows the check code and only the signed in owner\'s own clients and machines', function () {
        $pairing = spStart();
        $user = User::factory()->create();
        $client = Client::factory()->for($user)->create(['name' => 'Bakkerij Jansen']);
        $laptop = Machine::factory()->for($user)->create(['client_id' => $client->id, 'name' => 'Kassa-PC']);
        Machine::factory()->for($user)->create(['client_id' => $client->id, 'name' => 'Old-PC'])->delete();
        Machine::factory()->for($user)->create(['client_id' => null, 'name' => 'Loose-PC']);
        Client::factory()->for($user)->create(['name' => 'Gone'])->delete();
        $other = User::factory()->create();
        $theirs = Client::factory()->for($other)->create(['name' => 'Not Yours']);
        Machine::factory()->for($other)->create(['client_id' => $theirs->id, 'name' => 'Their-PC']);

        $response = actingAs($user)->get(route('pairings.show', ['pairing' => $pairing['id']]));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('pairings/Show')
            ->where('pairing.checkCode', $pairing['check_code'])
            ->where('pairing.status', 'pending')
            ->where('pairing.expiresAt', ScanPairing::query()->sole()->expires_at->toIso8601String())
            ->where('pairing.client', null)
            ->where('pairing.machine', null)
            ->where('clients', [['id' => $client->id, 'name' => 'Bakkerij Jansen', 'machines' => [['id' => $laptop->id, 'label' => 'Kassa-PC']]]]));
        expect($response->headers->get('Referrer-Policy'))->toBe('no-referrer')
            ->and($response->headers->get('Cache-Control'))->toContain('no-store');
    });

    it('labels a machine without a name by its model, then its serial, then its number, sorted by that label', function () {
        $pairing = spStart();
        $user = User::factory()->create();
        $client = Client::factory()->for($user)->create();
        $model = Machine::factory()->for($user)->create(['client_id' => $client->id, 'name' => null, 'model' => 'ThinkPad T14']);
        $serial = Machine::factory()->for($user)->create(['client_id' => $client->id, 'name' => null, 'model' => null, 'serial' => 'PF2ABC']);
        $bare = Machine::factory()->for($user)->create(['client_id' => $client->id, 'name' => null, 'model' => null, 'serial' => null]);
        $named = Machine::factory()->for($user)->create(['client_id' => $client->id, 'name' => 'kassa-pc']);

        actingAs($user)->get(route('pairings.show', ['pairing' => $pairing['id']]))->assertInertia(fn (Assert $page) => $page
            ->where('clients.0.machines', [
                ['id' => $named->id, 'label' => 'kassa-pc'],
                ['id' => $bare->id, 'label' => 'Machine '.$bare->id],
                ['id' => $serial->id, 'label' => 'PF2ABC'],
                ['id' => $model->id, 'label' => 'ThinkPad T14'],
            ]));
    });

    it('answers 404 for an id that does not exist or is not an id', function (string $id) {
        actingAs(User::factory()->create())->get('/p/'.rawurlencode($id))->assertNotFound();
    })->with(['unknown' => [Str::random(24)], 'short' => ['abc'], 'unicode' => [str_repeat('é', 24)]]);

    it('shows an expired request as expired, with nothing to choose from', function () {
        $pairing = spStart();
        $user = User::factory()->create();
        Client::factory()->for($user)->create();
        $this->travel(11)->minutes();

        actingAs($user)->get(route('pairings.show', ['pairing' => $pairing['id']]))->assertInertia(fn (Assert $page) => $page
            ->where('pairing.status', 'expired')
            ->where('clients', []));
    });

    it('shows the owner what they chose, and hides a decided request from everyone else', function () {
        $pairing = spStart();
        $user = User::factory()->create();
        $client = Client::factory()->for($user)->create(['name' => 'Bakkerij Jansen']);
        $machine = Machine::factory()->for($user)->create(['client_id' => $client->id, 'name' => 'Kassa-PC']);
        spApprove($user, $pairing['id'], ['client_id' => $client->id, 'machine_id' => $machine->id]);

        actingAs($user)->get(route('pairings.show', ['pairing' => $pairing['id']]))->assertInertia(fn (Assert $page) => $page
            ->where('pairing.status', 'approved')
            ->where('pairing.client', ['id' => $client->id, 'name' => 'Bakkerij Jansen'])
            ->where('pairing.machine', ['id' => $machine->id, 'label' => 'Kassa-PC'])
            ->where('clients', []));

        actingAs(User::factory()->create())->get(route('pairings.show', ['pairing' => $pairing['id']]))->assertNotFound();
    });
});

describe('approving and rejecting', function () {
    it('approves into an existing machine of a client', function () {
        $this->freezeTime();
        $pairing = spStart();
        $user = User::factory()->create();
        $client = Client::factory()->for($user)->create();
        $machine = Machine::factory()->for($user)->create(['client_id' => $client->id]);

        spApprove($user, $pairing['id'], ['client_id' => $client->id, 'machine_id' => $machine->id])
            ->assertRedirect(route('pairings.show', ['pairing' => $pairing['id']]));

        $row = ScanPairing::query()->sole();
        expect($row->status())->toBe(PairingStatus::Approved)
            ->and($row->user_id)->toBe($user->id)
            ->and($row->client_id)->toBe($client->id)
            ->and($row->machine_id)->toBe($machine->id)
            ->and($row->token_hash)->toBeNull()
            ->and($row->token_expires_at?->toIso8601String())->toBe(now()->addMinutes(30)->toIso8601String());
    });

    it('approves into a new machine for a client', function () {
        $pairing = spStart();
        $user = User::factory()->create();
        $client = Client::factory()->for($user)->create();

        spApprove($user, $pairing['id'], ['client_id' => $client->id, 'machine_id' => null])->assertRedirect();

        expect(ScanPairing::query()->sole())->client_id->toBe($client->id)->machine_id->toBeNull();
    });

    it('refuses a client or machine that is not this owner\'s, or does not fit', function (Closure $choice, string $field) {
        $pairing = spStart();
        $user = User::factory()->create();
        $client = Client::factory()->for($user)->create();

        spApprove($user, $pairing['id'], $choice($user, $client))->assertSessionHasErrors($field);

        expect(ScanPairing::query()->sole()->status())->toBe(PairingStatus::Pending);
    })->with([
        "another owner's client" => [fn () => ['client_id' => Client::factory()->create()->id], 'client_id'],
        "another owner's machine" => [fn (User $u, Client $c) => ['client_id' => $c->id, 'machine_id' => Machine::factory()->create()->id], 'machine_id'],
        "another owner's machine in my client" => [fn (User $u, Client $c) => ['client_id' => $c->id, 'machine_id' => Machine::factory()->create(['client_id' => $c->id])->id], 'machine_id'],
        'a machine of my other client' => [fn (User $u, Client $c) => ['client_id' => $c->id, 'machine_id' => Machine::factory()->for($u)->create(['client_id' => Client::factory()->for($u)->create()->id])->id], 'machine_id'],
        'my machine without a client' => [fn (User $u, Client $c) => ['client_id' => $c->id, 'machine_id' => Machine::factory()->for($u)->create(['client_id' => null])->id], 'machine_id'],
        'a deleted client' => [fn (User $u) => ['client_id' => tap(Client::factory()->for($u)->create())->delete()->id], 'client_id'],
        'a deleted machine' => [fn (User $u, Client $c) => ['client_id' => $c->id, 'machine_id' => tap(Machine::factory()->for($u)->create(['client_id' => $c->id]))->delete()->id], 'machine_id'],
        'no client' => [fn () => [], 'client_id'],
        'text' => [fn () => ['client_id' => 'abc'], 'client_id'],
        'an array' => [fn (User $u, Client $c) => ['client_id' => $c->id, 'machine_id' => [1]], 'machine_id'],
    ]);

    it('rejects, and a rejected request cannot be approved after all', function () {
        $pairing = spStart();
        $user = User::factory()->create();
        $client = Client::factory()->for($user)->create();

        actingAs($user)->post(route('pairings.reject', ['pairing' => $pairing['id']]))
            ->assertRedirect(route('pairings.show', ['pairing' => $pairing['id']]));
        spApprove($user, $pairing['id'], ['client_id' => $client->id])->assertRedirect();

        expect(ScanPairing::query()->sole())
            ->status()->toBe(PairingStatus::Rejected)
            ->user_id->toBe($user->id)
            ->client_id->toBeNull();
    });

    it('changes nothing once the request has expired', function () {
        $pairing = spStart();
        $user = User::factory()->create();
        $client = Client::factory()->for($user)->create();
        $this->travel(11)->minutes();

        spApprove($user, $pairing['id'], ['client_id' => $client->id])->assertRedirect();
        actingAs($user)->post(route('pairings.reject', ['pairing' => $pairing['id']]))->assertRedirect();

        expect(ScanPairing::query()->sole())->approved_at->toBeNull()->rejected_at->toBeNull()->user_id->toBeNull();
    });

    it('keeps the first approval when it is approved twice', function () {
        $pairing = spStart();
        $user = User::factory()->create();
        $first = Client::factory()->for($user)->create();
        $second = Client::factory()->for($user)->create();

        spApprove($user, $pairing['id'], ['client_id' => $first->id]);
        spApprove($user, $pairing['id'], ['client_id' => $second->id]);

        expect(ScanPairing::query()->sole()->client_id)->toBe($first->id);
    });

    it('does not let a second owner touch a request someone else already approved', function () {
        $pairing = spStart();
        $owner = User::factory()->create();
        $client = Client::factory()->for($owner)->create();
        spApprove($owner, $pairing['id'], ['client_id' => $client->id]);
        $intruder = User::factory()->create();

        spApprove($intruder, $pairing['id'], ['client_id' => Client::factory()->for($intruder)->create()->id])->assertNotFound();
        actingAs($intruder)->post(route('pairings.reject', ['pairing' => $pairing['id']]))->assertNotFound();

        expect(ScanPairing::query()->sole())->client_id->toBe($client->id)->rejected_at->toBeNull();
    });

    it('sends guests to the login without approving', function () {
        $pairing = spStart();
        $client = Client::factory()->create();

        post(route('pairings.approve', ['pairing' => $pairing['id']]), ['client_id' => $client->id])->assertRedirect(route('login'));
        post(route('pairings.reject', ['pairing' => $pairing['id']]))->assertRedirect(route('login'));

        expect(ScanPairing::query()->sole()->status())->toBe(PairingStatus::Pending);
    });

    it('needs the CSRF token to approve or reject', function () {
        app()->instance(PreventRequestForgery::class, new class(app(), app('encrypter')) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $pairing = spStart();
        $user = User::factory()->create();
        $client = Client::factory()->for($user)->create();

        spApprove($user, $pairing['id'], ['client_id' => $client->id])->assertStatus(419);
        actingAs($user)->post(route('pairings.reject', ['pairing' => $pairing['id']]))->assertStatus(419);

        expect(ScanPairing::query()->sole()->status())->toBe(PairingStatus::Pending);
    });

    it('refuses in the actions themselves a client or machine that is not the approver\'s', function () {
        $user = User::factory()->create();
        $client = Client::factory()->for($user)->create();
        $foreignClient = Client::factory()->create();
        $foreignMachine = Machine::factory()->create(['client_id' => $client->id]);
        $otherClientsMachine = Machine::factory()->for($user)->create(['client_id' => Client::factory()->for($user)->create()->id]);
        $pairing = ScanPairing::factory()->create();
        $approve = app(ApprovePairing::class);

        expect($approve->handle($pairing, $user, $foreignClient, null))->toBeFalse()
            ->and($approve->handle($pairing, $user, $client, $foreignMachine))->toBeFalse()
            ->and($approve->handle($pairing, $user, $client, $otherClientsMachine))->toBeFalse()
            ->and($pairing->refresh()->status())->toBe(PairingStatus::Pending)
            ->and(app(RejectPairing::class)->handle($pairing, $user))->toBeTrue()
            ->and(app(RejectPairing::class)->handle($pairing, $user))->toBeFalse();
    });
});

describe('uploading with the approval', function () {
    it('files the report under the chosen machine and client, once', function () {
        $user = User::factory()->create();
        $client = Client::factory()->for($user)->create();
        $machine = spDesktopMachine($user, $client);
        $token = spApprovedToken($user, $client, $machine);
        $machine->forceFill(['client_id' => Client::factory()->for($user)->create()->id])->save();

        spUpload($token, fixtureReport())->assertCreated()->assertJsonPath('machine_id', $machine->id);

        expect($machine->refresh()->client_id)->toBe($client->id)
            ->and($machine->reports()->count())->toBe(1)
            ->and(ScanPairing::query()->sole())->status()->toBe(PairingStatus::Used)->machine_id->toBe($machine->id);

        spUpload($token, fixtureReport())->assertUnprocessable()->assertJsonPath('errors.upload_token.0', fn (string $m) => str_contains($m, 'not valid'));
    });

    it('creates the machine for the chosen client when a new machine was chosen', function () {
        $user = User::factory()->create();
        $client = Client::factory()->for($user)->create();

        $response = spUpload(spApprovedToken($user, $client), fixtureReport())->assertCreated();
        $machine = Machine::query()->findOrFail($response->json('machine_id'));

        expect($machine->user_id)->toBe($user->id)
            ->and($machine->client_id)->toBe($client->id)
            ->and(ScanPairing::query()->sole()->machine_id)->toBe($machine->id);
    });

    it('moves a machine the owner already had to the client chosen for a new machine', function () {
        $user = User::factory()->create();
        $chosen = Client::factory()->for($user)->create();
        $machine = spDesktopMachine($user, Client::factory()->for($user)->create());

        spUpload(spApprovedToken($user, $chosen), fixtureReport())->assertCreated()->assertJsonPath('machine_id', $machine->id);

        expect($machine->refresh()->client_id)->toBe($chosen->id);
    });

    it('refuses a report from another computer than the chosen machine, keeps nothing and hands the token back', function () {
        $user = User::factory()->create();
        $client = Client::factory()->for($user)->create();
        $chosen = Machine::factory()->for($user)->create(['client_id' => $client->id]);
        $token = spApprovedToken($user, $client, $chosen);

        spUpload($token, macReportFixture())->assertUnprocessable()->assertJsonValidationErrors('markdown');

        expect(Report::query()->count())->toBe(0)
            ->and($user->machines()->count())->toBe(1)
            ->and(ScanPairing::query()->sole()->used_at)->toBeNull();

        $chosen->forceFill(['identity_key' => 'serial:TESTSERIAL001'])->save();
        spUpload($token, macReportFixture())->assertCreated()->assertJsonPath('machine_id', $chosen->id);
    });

    it('hands the token back when the report itself is rejected', function () {
        $user = User::factory()->create();
        $token = spApprovedToken($user, Client::factory()->for($user)->create());

        spUpload($token, "# Nothing here\n")->assertUnprocessable()->assertJsonValidationErrors('report');
        spUpload($token, fixtureReport())->assertCreated();
    });

    it('stores the report in the approving owner\'s account only', function () {
        $other = User::factory()->create();
        $theirs = spDesktopMachine($other);
        $user = User::factory()->create();

        spUpload(spApprovedToken($user, Client::factory()->for($user)->create()), fixtureReport())->assertCreated();

        expect($theirs->reports()->count())->toBe(0)
            ->and($user->machines()->sole()->reports()->count())->toBe(1);
    });

    it('refuses a token after its 30 minutes', function () {
        $user = User::factory()->create();
        $token = spApprovedToken($user, Client::factory()->for($user)->create());
        $this->travel(31)->minutes();

        spUpload($token, fixtureReport())->assertUnprocessable();
        expect(Report::query()->count())->toBe(0);
    });

    it('refuses when the client was deleted after the approval', function () {
        $user = User::factory()->create();
        $client = Client::factory()->for($user)->create();
        $token = spApprovedToken($user, $client);
        $client->delete();

        spUpload($token, fixtureReport())->assertUnprocessable();
        expect(Report::query()->count())->toBe(0);
    });

    it('refuses when the chosen machine was deleted after the approval', function () {
        $user = User::factory()->create();
        $client = Client::factory()->for($user)->create();
        $machine = spDesktopMachine($user, $client);
        $token = spApprovedToken($user, $client, $machine);
        $this->delete(route('machines.destroy', $machine))->assertRedirect();

        spUpload($token, fixtureReport())->assertUnprocessable();
        expect(Report::query()->count())->toBe(0);
    });

    it('refuses tokens that were never issued, whatever their shape', function (mixed $token) {
        spUpload($token, fixtureReport())->assertUnprocessable()->assertJsonPath('message', fn (string $m) => str_contains($m, 'approval'));

        expect(Report::query()->count())->toBe(0);
    })->with([
        'random' => [Str::random(40)],
        'short' => ['abc'],
        'unicode' => [str_repeat('é', 40)],
        'array' => [['x']],
        'number' => [12345],
    ]);

    it('feeds wrong tokens into the same throttle as wrong scan codes', function () {
        foreach (range(1, ScanCodeThrottle::ADDRESS_FAILURES) as $ignored) {
            spUpload(Str::random(40), fixtureReport(), '198.51.100.30')->assertUnprocessable();
        }

        spUpload(Str::random(40), fixtureReport(), '198.51.100.30')->assertTooManyRequests();
    });

    it('lets the token win over a scan code in the same body, leaving the code unused', function () {
        $user = User::factory()->create();
        $code = ScanCode::factory()->withCode('123456')->create();
        $token = spApprovedToken($user, Client::factory()->for($user)->create());

        test()->postJson('/api/scans', ['code' => '123456', 'upload_token' => $token, 'markdown' => fixtureReport()])->assertCreated();

        expect($code->refresh()->used_at)->toBeNull()->and($user->machines()->count())->toBe(1);
    });

    it('treats a null or empty upload token as no token, so the scan code still works', function (mixed $token) {
        $user = User::factory()->create();
        ScanCode::factory()->for($user)->withCode('246810')->create();

        test()->postJson('/api/scans', ['code' => '246810', 'upload_token' => $token, 'markdown' => fixtureReport()])->assertCreated();
    })->with(['null' => [null], 'empty' => ['']]);

    it('still takes a scan code exactly as before', function () {
        $user = User::factory()->create();
        ScanCode::factory()->for($user)->withCode('654321')->create();

        test()->postJson('/api/scans', ['code' => '654321', 'markdown' => fixtureReport()])->assertCreated();
        test()->postJson('/api/scans', ['code' => '654321', 'markdown' => fixtureReport()])
            ->assertUnprocessable()->assertJsonPath('errors.code.0', fn (string $m) => str_contains($m, 'scan code'));
    });
});

describe('what reaches the error tracker', function () {
    it('keeps pairing ids out of the reported URL and route parameters', function (string $prefix) {
        Http::fake(['*' => Http::response(['status' => 'ok'])]);
        config()->set('flare-client.enabled', true);
        config()->set('flare-client.key', 'test-key');
        config()->set('flare-client.spool.enabled', false);
        $id = Str::random(24);
        Route::middleware('web')->get($prefix.'{pairing}/boom', fn () => throw new RuntimeException('Pairing page broke.'));

        test()->get($prefix.$id.'/boom?x=1')->assertStatus(500);

        Http::assertSent(fn ($request) => ! str_contains($request->body(), $id)
            && str_contains((string) $request['request']['url'], $prefix.'[filtered]/boom')
            && $request['request']['route_params']['pairing'] === '[filtered]');
    })->with(['approve page' => ['/p/'], 'poll' => ['/scripts/pair/']]);

    it('leaves other URLs alone', function () {
        Http::fake(['*' => Http::response(['status' => 'ok'])]);
        config()->set('flare-client.enabled', true);
        config()->set('flare-client.key', 'test-key');
        config()->set('flare-client.spool.enabled', false);
        Route::middleware('web')->get('/pp/{thing}', fn () => throw new RuntimeException('Elsewhere.'));

        test()->get('/pp/visible')->assertStatus(500);

        Http::assertSent(fn ($request) => str_ends_with((string) $request['request']['url'], '/pp/visible'));
    });
});
