<?php

declare(strict_types=1);

use App\Models\Machine;
use App\Models\Report;
use App\Models\ScanCode;
use App\Models\User;
use Illuminate\Http\Response;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\assertDatabaseCount;
use function Pest\Laravel\call;

const NEUTRAL_MESSAGE = 'That scan code is not valid, has expired or was already used. Start a new scan in the app.';

function scanReport(string $serial = 'C02SCANAAA111'): string
{
    return "# Mac checkup: Scan-Mac\n\n- Format: 1\n- OS: macOS\n- Device type: laptop\n- Serial: {$serial}\n\n## Things to look at\n\n- Nothing flagged automatically.\n";
}

/**
 * @param  array<string, mixed>  $payload
 * @return TestResponse<Response>
 */
function redeem(array $payload, string $ip = '10.0.0.1'): TestResponse
{
    return test()->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/api/scans', $payload);
}

function codeFor(User $user, string $code = '123456'): ScanCode
{
    return ScanCode::factory()->for($user)->withCode($code)->create();
}

describe('redeeming a scan code', function () {
    it('imports the report for the code owner without any login or token', function () {
        $user = User::factory()->create();
        codeFor($user);

        $response = redeem(['code' => '123456', 'markdown' => scanReport()]);

        $response->assertCreated()->assertJsonPath('created', true);
        $machine = Machine::query()->sole();
        expect($machine->user_id)->toBe($user->id)
            ->and($machine->serial)->toBe('C02SCANAAA111')
            ->and($response->json('machine_id'))->toBe($machine->id)
            ->and(array_keys((array) $response->json()))->toBe(['machine_id', 'report_id', 'created']);
    });

    it('burns the code and links it to the report', function () {
        $user = User::factory()->create();
        $scan = codeFor($user);

        redeem(['code' => '123456', 'markdown' => scanReport()])->assertCreated();

        $scan->refresh();
        expect($scan->used_at)->not->toBeNull()
            ->and($scan->report_id)->toBe(Report::query()->sole()->id);
    });

    it('rejects the right code a second time', function () {
        codeFor(User::factory()->create());

        redeem(['code' => '123456', 'markdown' => scanReport()])->assertCreated();
        redeem(['code' => '123456', 'markdown' => scanReport('C02SCANBBB222')])
            ->assertUnprocessable()->assertJsonPath('message', NEUTRAL_MESSAGE);

        assertDatabaseCount('reports', 1);
    });

    it('lets the report decide the machine, reusing one the account already has', function () {
        $user = User::factory()->create();
        codeFor($user);
        redeem(['code' => '123456', 'markdown' => scanReport()])->assertCreated();
        $user->scanCode()->delete();
        codeFor($user, '654321');

        redeem(['code' => '654321', 'markdown' => scanReport()])->assertOk()->assertJsonPath('created', false);

        assertDatabaseCount('machines', 1);
        assertDatabaseCount('reports', 1);
    });

    it('accepts a code with leading zeros and one padded with whitespace', function () {
        $user = User::factory()->create();
        codeFor($user, '000123');

        redeem(['code' => " 000123\n", 'markdown' => scanReport()])->assertCreated();
    });

    it('only ever adds to the account that created the code', function () {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        codeFor($owner, '111111');
        $otherScan = codeFor($other, '222222');
        $otherMachine = Machine::factory()->for($other)->create(['identity_key' => 'serial:c02scanaaa111']);

        redeem(['code' => '111111', 'markdown' => scanReport()])->assertCreated();

        expect($owner->machines()->count())->toBe(1)
            ->and($other->machines()->count())->toBe(1)
            ->and($otherMachine->reports()->count())->toBe(0)
            ->and($otherScan->refresh()->used_at)->toBeNull();
    });

    it('gives a used, expired, replaced and unknown code the same answer', function () {
        $user = User::factory()->create();
        ScanCode::factory()->for($user)->withCode('111111')->used()->create();
        ScanCode::factory()->for(User::factory())->withCode('222222')->expired()->create();
        codeFor(User::factory()->create(), '444444');

        $answers = collect(['111111', '222222', '333333'])
            ->map(fn (string $code) => redeem(['code' => $code, 'markdown' => scanReport()]))
            ->each->assertUnprocessable()
            ->map(fn (TestResponse $response) => $response->getContent());

        expect($answers->unique())->toHaveCount(1)
            ->and(json_decode((string) $answers->first(), true))->toBe([
                'message' => NEUTRAL_MESSAGE,
                'errors' => ['code' => [NEUTRAL_MESSAGE]],
            ]);
        assertDatabaseCount('reports', 0);
    });

    it('no longer accepts a code once the user starts a new one', function () {
        $this->actingAs(User::factory()->create());
        $this->post('/scans');
        $old = (string) session('scan_code');
        $this->post('/scans');
        $new = (string) session('scan_code');

        expect($old)->toMatch('/^\d{6}$/');
        assertDatabaseCount('scan_codes', 1);

        if ($old !== $new) {
            redeem(['code' => $old, 'markdown' => scanReport()])->assertUnprocessable();
        }
        redeem(['code' => $new, 'markdown' => scanReport()])->assertCreated();
    });

    it('does not burn the code when the report is not valid', function () {
        $scan = codeFor(User::factory()->create());

        redeem(['code' => '123456', 'markdown' => "# Nothing useful here\n"])
            ->assertUnprocessable()->assertJsonValidationErrors('report');

        expect($scan->refresh()->used_at)->toBeNull();
        redeem(['code' => '123456', 'markdown' => scanReport()])->assertCreated();
    });

    it('applies the report validation of the token API', function (mixed $markdown) {
        $scan = codeFor(User::factory()->create());

        redeem(['code' => '123456', 'markdown' => $markdown])->assertUnprocessable()->assertJsonValidationErrors('markdown');

        expect($scan->refresh()->used_at)->toBeNull();
        assertDatabaseCount('reports', 0);
    })->with([
        'missing' => [null],
        'empty' => [''],
        'array' => [['a' => 'b']],
        'number' => [42],
        'over two million characters' => [fn () => str_repeat('a', 2_000_001)],
    ]);

    it('answers a get with 405 and needs no csrf token', function () {
        $this->getJson('/api/scans')->assertMethodNotAllowed();
    });

    it('does not open the read API to the caller', function () {
        codeFor(User::factory()->create());
        redeem(['code' => '123456', 'markdown' => scanReport()])->assertCreated();

        $this->getJson('/api/machines')->assertUnauthorized();
        $this->getJson('/api/user')->assertUnauthorized();
    });
});

describe('odd values in the code field', function () {
    it('are rejected without burning or revealing anything', function (mixed $code) {
        $scan = codeFor(User::factory()->create(), '000123');

        $payload = ['markdown' => scanReport()];
        if ($code !== '__absent__') {
            $payload['code'] = $code instanceof Closure ? $code() : $code;
        }

        redeem($payload)->assertUnprocessable()->assertJsonPath('message', NEUTRAL_MESSAGE);

        expect($scan->refresh()->used_at)->toBeNull();
        assertDatabaseCount('reports', 0);
    })->with([
        'absent' => ['__absent__'],
        'null' => [null],
        'empty' => [''],
        'spaces only' => ['      '],
        'five digits' => ['12345'],
        'seven digits' => ['1234567'],
        'letters' => ['abcdef'],
        'mixed' => ['12345a'],
        'inner space' => ['123 456'],
        'dashed' => ['123-456'],
        'sign' => ['+00123'],
        'exponent' => ['1e5555'],
        'hex' => ['0x1234'],
        'arabic indic digits' => ['١٢٣٤٥٦'],
        'fullwidth digits' => ['１２３４５６'],
        'without leading zeros' => ['123'],
        'as a number' => [123],
        'as a float' => [123.0],
        'true' => [true],
        'list' => [['000123']],
        'object' => [['code' => '000123']],
        'nested wildcard' => [['%' => '%']],
        'sql shaped' => ["' OR 1=1 --"],
        'very long' => [fn () => str_repeat('1', 100_000)],
    ]);
});

describe('guessing', function () {
    it('locks an address after five wrong guesses, even for the right code', function () {
        codeFor(User::factory()->create());

        foreach (range(1, 5) as $guess) {
            redeem(['code' => sprintf('%06d', $guess), 'markdown' => scanReport()])->assertUnprocessable();
        }

        $locked = redeem(['code' => '123456', 'markdown' => scanReport()]);
        $locked->assertTooManyRequests()->assertHeader('Retry-After');
        expect($locked->json('message'))->toBe('Too many wrong codes. Wait a while before trying again.');
        assertDatabaseCount('reports', 0);
    });

    it('answers the same while locked whether the code is right or wrong', function () {
        codeFor(User::factory()->create());
        foreach (range(1, 5) as $guess) {
            redeem(['code' => sprintf('%06d', $guess), 'markdown' => scanReport()]);
        }

        $right = redeem(['code' => '123456', 'markdown' => scanReport()]);
        $wrong = redeem(['code' => '999999', 'markdown' => scanReport()]);

        expect($right->status())->toBe($wrong->status())->and($right->json())->toBe($wrong->json());
    });

    it('counts a malformed code as a wrong guess', function () {
        foreach (range(1, 5) as $ignored) {
            redeem(['code' => 'letters', 'markdown' => scanReport()])->assertUnprocessable();
        }

        redeem(['code' => 'letters', 'markdown' => scanReport()])->assertTooManyRequests();
    });

    it('leaves other addresses alone', function () {
        codeFor(User::factory()->create());
        foreach (range(1, 5) as $guess) {
            redeem(['code' => sprintf('%06d', $guess), 'markdown' => scanReport()], '10.0.0.1');
        }

        redeem(['code' => '123456', 'markdown' => scanReport()], '10.0.0.2')->assertCreated();
    });

    it('does not count good redemptions against an address', function () {
        foreach (range(1, 8) as $n) {
            codeFor(User::factory()->create(), sprintf('%06d', $n));
            redeem(['code' => sprintf('%06d', $n), 'markdown' => scanReport("C02SERIAL{$n}XYZ")])->assertCreated();
        }
    });

    it('does not count a rejected report as a wrong guess', function () {
        codeFor(User::factory()->create());

        foreach (range(1, 8) as $ignored) {
            redeem(['code' => '123456', 'markdown' => "# no identity\n"])->assertUnprocessable();
        }

        redeem(['code' => '123456', 'markdown' => scanReport()])->assertCreated();
    });

    it('locks everyone out after the global cap of failures, then lets them back in', function () {
        codeFor(User::factory()->create());

        foreach (range(1, 30) as $n) {
            redeem(['code' => 'wrong', 'markdown' => scanReport()], "10.1.0.{$n}")->assertUnprocessable();
        }

        redeem(['code' => '123456', 'markdown' => scanReport()], '10.9.9.9')
            ->assertTooManyRequests()->assertHeader('Retry-After');

        $this->travel(31)->minutes();
        ScanCode::query()->delete();
        codeFor(User::factory()->create());

        redeem(['code' => '123456', 'markdown' => scanReport()], '10.9.9.9')->assertCreated();
    });

    it('lifts the address lock after the window', function () {
        foreach (range(1, 5) as $ignored) {
            redeem(['code' => 'wrong', 'markdown' => scanReport()]);
        }
        redeem(['code' => 'wrong', 'markdown' => scanReport()])->assertTooManyRequests();

        $this->travel(11)->minutes();

        redeem(['code' => 'wrong', 'markdown' => scanReport()])->assertUnprocessable();
    });
});

describe('the endpoint itself', function () {
    it('is reachable without a session or token', function () {
        call('POST', '/api/scans', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], '{"code":"1","markdown":"x"}')
            ->assertUnprocessable();
    });
});
