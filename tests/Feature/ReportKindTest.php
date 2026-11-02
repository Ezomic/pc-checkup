<?php

declare(strict_types=1);

use App\Actions\Machines\ImportReport;
use App\Actions\Scans\RedeemScanCode;
use App\Enums\ReportKind;
use App\Models\Machine;
use App\Models\Report;
use App\Models\ScanCode;
use App\Models\User;
use App\Services\Reports\ReportParser;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

function kindReport(string $header = '', string $sections = '', string $serial = 'C02KIND000001'): string
{
    return "# Mac checkup: Kind-Mac\n\n- Format: 2\n{$header}- OS: macOS\n- Device type: laptop\n- Serial: {$serial}\n\n## Things to look at\n\n- Firewall is off.\n{$sections}";
}

function afterFixReport(string $beforeChecksum, string $sections = ''): string
{
    return kindReport("- Kind: after-fix\n- Before checksum: {$beforeChecksum}\n", $sections);
}

describe('report kind, fix log and benchmark parsing', function () {
    it('defaults to an audit without fixes or benchmark', function () {
        $parsed = (new ReportParser)->parse(kindReport());

        expect($parsed->kind)->toBe(ReportKind::Audit)
            ->and($parsed->beforeChecksum)->toBeNull()
            ->and($parsed->fixes)->toBeNull()
            ->and($parsed->benchmark)->toBeNull();
    });

    it('reads an after-fix kind and its before checksum', function () {
        $checksum = str_repeat('AB', 32);

        $parsed = (new ReportParser)->parse(afterFixReport($checksum));

        expect($parsed->kind)->toBe(ReportKind::AfterFix)
            ->and($parsed->beforeChecksum)->toBe(mb_strtolower($checksum));
    });

    it('treats an unknown kind as an audit and ignores a malformed checksum', function () {
        $parsed = (new ReportParser)->parse(kindReport("- Kind: weird\n- Before checksum: nope\n"));

        expect($parsed->kind)->toBe(ReportKind::Audit)
            ->and($parsed->beforeChecksum)->toBeNull();
    });

    it('reads the fix log', function () {
        $sections = <<<'MD'

## Fix log

- firewall-off | applied | sudo /usr/libexec/ApplicationFirewall/socketfilterfw --setglobalstate on | undo: sudo /usr/libexec/ApplicationFirewall/socketfilterfw --setglobalstate off
- caches-large | skipped | rm -rf ~/Library/Caches/* | undo: none
- updates-pending | failed | softwareupdate -i -a | undo: none
- not a fix line
- bogus-status | maybe | echo hi | undo: none
- piped | applied | echo a | tr a b | undo: x
MD;

        $fixes = (new ReportParser)->parse(kindReport(sections: $sections))->fixes;

        expect($fixes)->toBe([
            ['id' => 'firewall-off', 'status' => 'applied', 'command' => 'sudo /usr/libexec/ApplicationFirewall/socketfilterfw --setglobalstate on', 'undo' => 'sudo /usr/libexec/ApplicationFirewall/socketfilterfw --setglobalstate off'],
            ['id' => 'caches-large', 'status' => 'skipped', 'command' => 'rm -rf ~/Library/Caches/*', 'undo' => null],
            ['id' => 'updates-pending', 'status' => 'failed', 'command' => 'softwareupdate -i -a', 'undo' => null],
            ['id' => 'piped', 'status' => 'applied', 'command' => 'echo a | tr a b', 'undo' => 'x'],
        ]);
    });

    it('reads an empty fix log as an empty list and caps its length', function () {
        $empty = (new ReportParser)->parse(kindReport(sections: "\n## Fix log\n\n"))->fixes;
        $many = (new ReportParser)->parse(kindReport(sections: "\n## Fix log\n\n".str_repeat("- a | applied | c | undo: none\n", 120)))->fixes;

        expect($empty)->toBe([])
            ->and($many)->toHaveCount(100);
    });

    it('reads the benchmark and keeps only known numeric keys', function () {
        $sections = <<<'MD'

## Benchmark

- cpu_single_ms: 812
- cpu_multi_ms: 233.5
- memory_mb_s: 21000
- disk_write_mb_s: 1500
- disk_read_mb_s: 2500
- net_down_mbps: 480.25
- net_up_mbps: 90
- mystery_key: 5
- cpu_single_ms_extra: 5
- disk_read_mb_s: fast
- not a key value line
MD;

        $benchmark = (new ReportParser)->parse(kindReport(sections: $sections))->benchmark;

        expect($benchmark)->toBe([
            'cpu_single_ms' => 812,
            'cpu_multi_ms' => 233.5,
            'memory_mb_s' => 21000,
            'disk_write_mb_s' => 1500,
            'disk_read_mb_s' => 2500,
            'net_down_mbps' => 480.25,
            'net_up_mbps' => 90,
        ]);
    });

    it('reads a benchmark with omitted keys and an empty one', function () {
        $partial = (new ReportParser)->parse(kindReport(sections: "\n## Benchmark\n\n- cpu_single_ms: 700\n"))->benchmark;
        $empty = (new ReportParser)->parse(kindReport(sections: "\n## Benchmark\n"))->benchmark;

        expect($partial)->toBe(['cpu_single_ms' => 700])
            ->and($empty)->toBe([]);
    });

    it('skips a benchmark value that is not finite', function () {
        $benchmark = (new ReportParser)->parse(kindReport(sections: "\n## Benchmark\n\n- cpu_single_ms: 1e999\n- cpu_multi_ms: 3\n"))->benchmark;

        expect($benchmark)->toBe(['cpu_multi_ms' => 3]);
    });
});

describe('importing report kind, fix log and benchmark', function () {
    it('stores a Format 1 report as an audit with nothing extra', function () {
        $user = User::factory()->create();

        $report = (new ImportReport)->handle($user, "# Mac checkup: Old\n\n- Format: 1\n- Serial: C02OLD000001\n\n## Things to look at\n\n- x\n");

        $report->refresh();
        expect($report->kind)->toBe(ReportKind::Audit)
            ->and($report->before_report_id)->toBeNull()
            ->and($report->fixes)->toBeNull()
            ->and($report->benchmark)->toBeNull()
            ->and($report->format_version)->toBe(1);
    });

    it('stores the fix log and benchmark of an audit', function () {
        $user = User::factory()->create();
        $sections = "\n## Fix log\n\n- firewall-off | applied | cmd | undo: none\n\n## Benchmark\n\n- cpu_single_ms: 800\n";

        $report = (new ImportReport)->handle($user, kindReport(sections: $sections))->refresh();

        expect($report->fixes)->toBe([['id' => 'firewall-off', 'status' => 'applied', 'command' => 'cmd', 'undo' => null]])
            ->and($report->benchmark)->toBe(['cpu_single_ms' => 800])
            ->and($report->format_version)->toBe(2);
    });

    it('links an after-fix report to the audit it follows', function () {
        $user = User::factory()->create();
        $audit = (new ImportReport)->handle($user, kindReport());

        $after = (new ImportReport)->handle($user, afterFixReport($audit->checksum, "\n## Fix log\n\n- firewall-off | applied | cmd | undo: none\n"))->refresh();

        expect($after->kind)->toBe(ReportKind::AfterFix)
            ->and($after->before_report_id)->toBe($audit->id)
            ->and($after->beforeReport?->is($audit))->toBeTrue()
            ->and($after->machine_id)->toBe($audit->machine_id);
    });

    it('imports an after-fix report standalone when no stored audit matches', function () {
        $user = User::factory()->create();

        $after = (new ImportReport)->handle($user, afterFixReport(str_repeat('a', 64)))->refresh();

        expect($after->kind)->toBe(ReportKind::AfterFix)
            ->and($after->before_report_id)->toBeNull();
    });

    it('imports an after-fix report without a before checksum standalone', function () {
        $user = User::factory()->create();

        $after = (new ImportReport)->handle($user, kindReport("- Kind: after-fix\n"))->refresh();

        expect($after->kind)->toBe(ReportKind::AfterFix)
            ->and($after->before_report_id)->toBeNull();
    });

    it('does not link to an audit of another machine or another user', function () {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $elsewhere = (new ImportReport)->handle($other, kindReport());
        $sameUserOtherMachine = (new ImportReport)->handle($user, kindReport(serial: 'C02KIND000002'));

        $afterOther = (new ImportReport)->handle($user, afterFixReport($elsewhere->checksum))->refresh();
        $afterMachine = (new ImportReport)->handle($user, afterFixReport($sameUserOtherMachine->checksum))->refresh();

        expect($afterOther->before_report_id)->toBeNull()
            ->and($afterMachine->before_report_id)->toBeNull();
    });

    it('does not link to a report that is itself an after-fix', function () {
        $user = User::factory()->create();
        $first = (new ImportReport)->handle($user, afterFixReport(str_repeat('b', 64)));

        $second = (new ImportReport)->handle($user, afterFixReport($first->checksum, "\n## Benchmark\n\n- cpu_single_ms: 1\n"))->refresh();

        expect($second->before_report_id)->toBeNull();
    });

    it('does not link to a deleted audit', function () {
        $user = User::factory()->create();
        $audit = (new ImportReport)->handle($user, kindReport());
        $audit->delete();

        $after = (new ImportReport)->handle($user, afterFixReport($audit->checksum))->refresh();

        expect($after->before_report_id)->toBeNull();
    });

    it('nulls the link when the audit is force deleted', function () {
        $user = User::factory()->create();
        $audit = (new ImportReport)->handle($user, kindReport());
        $after = (new ImportReport)->handle($user, afterFixReport($audit->checksum));

        $audit->forceDelete();

        expect($after->refresh()->before_report_id)->toBeNull()
            ->and(Report::query()->count())->toBe(1);
    });

    it('keeps the same report when an after-fix report is uploaded twice', function () {
        $user = User::factory()->create();
        $audit = (new ImportReport)->handle($user, kindReport());
        $markdown = afterFixReport($audit->checksum);

        $first = (new ImportReport)->handle($user, $markdown);
        $second = (new ImportReport)->handle($user, $markdown);

        expect($second->id)->toBe($first->id)
            ->and(Report::query()->count())->toBe(2);
    });
});

describe('uploading an after-fix report', function () {
    it('links through the token endpoint', function () {
        $user = User::factory()->create();
        $audit = (new ImportReport)->handle($user, kindReport());
        actingAs($user);
        $token = $user->createToken('t', ['write'])->plainTextToken;

        $response = postJson('/api/reports', ['markdown' => afterFixReport($audit->checksum, "\n## Benchmark\n\n- cpu_single_ms: 9\n")], ['Authorization' => "Bearer {$token}"]);

        $response->assertCreated();
        $report = Report::query()->findOrFail($response->json('report_id'));
        expect($report->before_report_id)->toBe($audit->id)
            ->and($report->benchmark)->toBe(['cpu_single_ms' => 9]);
    });

    it('links through a scan code', function () {
        $user = User::factory()->create();
        $audit = (new ImportReport)->handle($user, kindReport());
        ScanCode::factory()->for($user)->withCode('654321')->create();

        $report = app(RedeemScanCode::class)->handle('654321', afterFixReport($audit->checksum));

        expect($report?->refresh()->before_report_id)->toBe($audit->id)
            ->and(Machine::query()->count())->toBe(1);
    });
});
