<?php

declare(strict_types=1);

use App\Actions\ApiTokens\CreateApiToken;
use App\Actions\Bench\CountUploadedBytes;
use App\Enums\ApiAbility;
use App\Models\ScanCode;
use App\Models\User;
use App\Services\Scans\ScanCodeThrottle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

const BENCH_MAX = 52428800;

function writeToken(): string
{
    return app(CreateApiToken::class)->handle(User::factory()->create(), 'Script', [ApiAbility::Write]);
}

/**
 * @param  array<string, string>  $server
 * @return TestResponse<Response>
 */
function benchUpload(string $body, array $headers = [], array $server = []): TestResponse
{
    $server += ['CONTENT_TYPE' => 'application/octet-stream'];

    foreach ($headers as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    return test()->call('POST', '/api/bench/upload', [], [], [], $server, $body);
}

describe('authentication', function () {
    it('rejects a request with no credentials before any bytes are produced', function () {
        test()->getJson('/api/bench/download?bytes=1000')->assertUnauthorized();
        benchUpload('abc')->assertUnauthorized();
    });

    it('rejects an unknown token', function () {
        test()->withToken('1|nope')->getJson('/api/bench/download?bytes=10')->assertUnauthorized();
    });

    it('rejects a read only token with a 403', function () {
        $plain = app(CreateApiToken::class)->handle(User::factory()->create(), 'Script', [ApiAbility::Read]);

        test()->withToken($plain)->getJson('/api/bench/download?bytes=10')->assertForbidden();
        benchUpload('abc', ['Authorization' => "Bearer {$plain}"])->assertForbidden();
    });

    it('accepts a write token', function () {
        test()->withToken(writeToken())->get('/api/bench/download?bytes=10')->assertOk();
    });

    it('accepts an active scan code in a header and leaves the code unused', function () {
        $scan = ScanCode::factory()->for(User::factory())->withCode('123456')->create();

        test()->withHeader('X-Scan-Code', '123456')->get('/api/bench/download?bytes=10')->assertOk();
        benchUpload('abc', ['X-Scan-Code' => '123456'])->assertOk()->assertExactJson(['bytes' => 3]);

        expect($scan->refresh()->used_at)->toBeNull();
    });

    it('rejects a wrong, malformed, expired or used scan code', function (string $code) {
        ScanCode::factory()->for(User::factory())->withCode('111111')->expired()->create();
        ScanCode::factory()->for(User::factory())->withCode('222222')->used()->create();

        test()->withHeader('X-Scan-Code', $code)->getJson('/api/bench/download?bytes=10')->assertUnauthorized();
    })->with(['999999', 'abc', '111111', '222222', '']);

    it('stops guessing scan codes with the redeem throttle', function () {
        foreach (range(1, ScanCodeThrottle::ADDRESS_FAILURES) as $ignored) {
            test()->withHeader('X-Scan-Code', '000000')->getJson('/api/bench/download?bytes=10')->assertUnauthorized();
        }

        ScanCode::factory()->for(User::factory())->withCode('123456')->create();

        test()->withHeader('X-Scan-Code', '123456')->getJson('/api/bench/download?bytes=10')->assertTooManyRequests();
    });
});

describe('download', function () {
    it('streams the default 25 MB uncompressed', function () {
        $response = test()->withToken(writeToken())->withHeader('Accept-Encoding', 'gzip')->get('/api/bench/download');

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/octet-stream')
            ->assertHeader('Content-Length', (string) (25 * 1024 * 1024))
            ->assertHeader('Content-Encoding', 'identity');
    });

    it('returns exactly the requested number of random bytes', function () {
        $response = test()->withToken(writeToken())->get('/api/bench/download?bytes=70001');
        $body = $response->streamedContent();

        expect(strlen($body))->toBe(70001)
            ->and($body)->not->toBe(str_repeat($body[0], 70001));
    });

    it('allows exactly the maximum', function () {
        $response = test()->withToken(writeToken())->get('/api/bench/download?bytes='.BENCH_MAX);

        $response->assertOk()->assertHeader('Content-Length', (string) BENCH_MAX);
    });

    it('refuses sizes outside the caps', function (string $bytes) {
        test()->withToken(writeToken())->getJson('/api/bench/download?bytes='.$bytes)
            ->assertUnprocessable()->assertJsonValidationErrors('bytes');
    })->with([(string) (BENCH_MAX + 1), '0', '-5', 'lots', '1.5']);
});

describe('upload', function () {
    it('reports the size of the raw body', function () {
        benchUpload(str_repeat('x', 200000), ['Authorization' => 'Bearer '.writeToken()])
            ->assertOk()->assertExactJson(['bytes' => 200000]);
    });

    it('accepts an empty body', function () {
        benchUpload('', ['Authorization' => 'Bearer '.writeToken()])->assertOk()->assertExactJson(['bytes' => 0]);
    });

    it('counts exactly the maximum and refuses one byte more while reading', function () {
        $action = app(CountUploadedBytes::class);
        $stream = static function (int $bytes) {
            $resource = fopen('php://temp', 'w+b');
            $chunk = str_repeat('a', 1048576);

            for ($left = $bytes; $left > 0; $left -= 1048576) {
                fwrite($resource, substr($chunk, 0, min($left, 1048576)));
            }

            rewind($resource);

            return $resource;
        };

        expect($action->handle(Request::create('/', 'POST', content: $stream(BENCH_MAX))))->toBe(BENCH_MAX);
        expect(fn () => $action->handle(Request::create('/', 'POST', content: $stream(BENCH_MAX + 1))))
            ->toThrow(HttpException::class);
    });

    it('refuses a declared length over the cap without reading the body', function () {
        $request = Request::create('/', 'POST', server: ['CONTENT_LENGTH' => (string) (BENCH_MAX + 1)], content: 'tiny');

        expect(fn () => app(CountUploadedBytes::class)->handle($request))->toThrow(HttpException::class);

        benchUpload('tiny', ['Authorization' => 'Bearer '.writeToken()], ['CONTENT_LENGTH' => (string) (BENCH_MAX + 1)])
            ->assertStatus(413);
    });
});

describe('rate limiting', function () {
    it('allows 6 a minute per token, tighter than the api limiter, and counts per token', function () {
        $plain = writeToken();

        foreach (range(1, 6) as $ignored) {
            test()->withToken($plain)->get('/api/bench/download?bytes=1')->assertOk();
        }

        test()->withToken($plain)->getJson('/api/bench/download?bytes=1')->assertTooManyRequests();

        app('auth')->forgetGuards();
        test()->withToken(writeToken())->get('/api/bench/download?bytes=1')->assertOk();
    });

    it('counts a scan code on its own', function () {
        ScanCode::factory()->for(User::factory())->withCode('123456')->create();

        foreach (range(1, 6) as $ignored) {
            test()->withHeader('X-Scan-Code', '123456')->get('/api/bench/download?bytes=1')->assertOk();
        }

        test()->withHeader('X-Scan-Code', '123456')->getJson('/api/bench/download?bytes=1')->assertTooManyRequests();
    });

    it('keys an unauthenticated request by address', function () {
        $request = Request::create('/api/bench/download', server: ['REMOTE_ADDR' => '203.0.113.7']);

        expect(RateLimiter::limiter('bench')($request)->key)->toBe('bench:ip:203.0.113.7');
    });
});
