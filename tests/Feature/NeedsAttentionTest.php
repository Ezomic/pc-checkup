<?php

declare(strict_types=1);

use App\Models\Client;
use App\Models\Machine;
use App\Models\Report;
use App\Models\User;
use App\Services\NeedsAttention;
use App\Services\Reports\ReportFreshness;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;

const ATTENTION_UNSUPPORTED = 'macOS 10.14.6 is old and likely out of security support.';
const ATTENTION_NOW = '2026-11-06 12:00:00';
const ATTENTION_REPAIR = 'The scan says this machine is worth repairing';

beforeEach(fn () => CarbonImmutable::setTestNow(ATTENTION_NOW));
afterEach(fn () => CarbonImmutable::setTestNow());

/**
 * @param  array<string, string|null>  $header
 */
function attentionMarkdown(array $header = []): string
{
    $lines = ['# Checkup', '', '- Format: 1'];

    foreach (['Model' => 'MacBook Pro (MacBookPro18,1)', 'macOS' => '14.5 (23F79)', 'Memory' => '16 GB', 'Free disk' => '50%', ...$header] as $key => $value) {
        if ($value !== null) {
            $lines[] = "- {$key}: {$value}";
        }
    }

    return implode("\n", $lines)."\n\n## Storage (boot volume)\n\n```\n   Solid State:               Yes\n```\n";
}

/**
 * @param  list<string>  $flags
 */
function attentionMachine(User $user, string|false|null $markdown = null, array $flags = [], string $name = 'Alpha', ?CarbonImmutable $scanned = null, ?CarbonImmutable $created = null): Machine
{
    $machine = Machine::factory()->for($user)->create(['name' => $name, 'created_at' => $created ?? CarbonImmutable::now()->subYear()]);

    if ($markdown !== false) {
        Report::factory()->for($machine)->create([
            'markdown' => $markdown ?? attentionMarkdown(),
            'flags' => $flags,
            'created_at' => $scanned ?? CarbonImmutable::now()->subDay(),
        ]);
    }

    return $machine;
}

/**
 * @return array{total: int, urgent: int, rows: list<array<string, mixed>>, showing_all: bool}
 */
function attentionFor(User $user, string $query = ''): array
{
    $found = [];

    actingAs($user)->get('/machines'.$query)->assertOk()->assertInertia(function ($page) use (&$found): void {
        $found = $page->toArray()['props']['needsAttention'];
    });

    return $found;
}

/**
 * @param  list<string>  $flags
 * @return array<string, mixed>|null
 */
function attentionRow(string $markdown, array $flags = []): ?array
{
    $user = User::factory()->create();
    attentionMachine($user, $markdown, $flags);

    return attentionFor($user)['rows'][0] ?? null;
}

/**
 * @param  list<string>  $flags
 * @return list<string>
 */
function attentionReasons(string $markdown, array $flags = []): array
{
    $row = attentionRow($markdown, $flags);

    return $row === null ? [] : array_column($row['reasons'], 'text');
}

it('stays empty for a healthy machine', function () {
    $user = User::factory()->create();
    attentionMachine($user);

    expect(attentionFor($user))->toBe(['total' => 0, 'urgent' => 0, 'rows' => [], 'showing_all' => false]);
});

it('treats the unsupported operating system as urgent', function () {
    $row = attentionRow(attentionMarkdown(['macOS' => '10.14.6']), [ATTENTION_UNSUPPORTED]);

    expect($row['level'])->toBe('urgent')
        ->and($row['reasons'])->toBe([
            ['level' => 'urgent', 'text' => 'The operating system no longer gets security updates'],
            ['level' => 'soon', 'text' => ATTENTION_REPAIR],
        ]);
});

it('rates free disk space at each boundary', function (string $free, ?string $level) {
    $row = attentionRow(attentionMarkdown(['Free disk' => $free]));

    expect($row['level'] ?? null)->toBe($level);
})->with([
    '4 percent' => ['4%', 'urgent'],
    '0 percent' => ['0%', 'urgent'],
    '5 percent' => ['5%', 'soon'],
    '9 percent' => ['9%', 'soon'],
    '10 percent' => ['10%', null],
    '100 percent' => ['100%', null],
    'above 100 is unreadable' => ['180%', null],
    'not available' => ['n/a', null],
    'no percent sign' => ['12', null],
]);

it('words the disk reason with the number', function () {
    expect(attentionReasons(attentionMarkdown(['Free disk' => '4%'])))->toBe(['Only 4% of the drive is free', ATTENTION_REPAIR])
        ->and(attentionReasons(attentionMarkdown(['Free disk' => '9%'])))->toBe(['Only 9% of the drive is free']);
});

it('does not count the disk flag a second time', function () {
    $row = attentionRow(attentionMarkdown(['Free disk' => '4%']), ['Only about 4% of disk is free.', 'Only about 3% of drive C: is free.']);

    expect($row['reason_count'])->toBe(2)
        ->and(array_column($row['reasons'], 'text'))->toBe(['Only 4% of the drive is free', ATTENTION_REPAIR]);
});

it('flags a worn out battery as urgent whatever its wording', function (string $condition) {
    $row = attentionRow(attentionMarkdown(['Battery' => "100 cycles, condition {$condition}, max capacity 95%"]));

    expect($row['level'])->toBe('urgent')
        ->and($row['reasons'][0]['text'])->toBe('The battery needs service or replacing');
})->with(['Service Recommended', 'Poor', 'Replace Soon', 'Replace Now']);

it('flags a weak battery as soon at the verdict boundaries', function (string $battery, ?string $level) {
    $row = attentionRow(attentionMarkdown(['Battery' => $battery]));

    expect($row['level'] ?? null)->toBe($level);
})->with([
    'capacity 80 is fine' => ['100 cycles, condition Normal, max capacity 80%', null],
    'capacity 79 is weak' => ['100 cycles, condition Normal, max capacity 79%', 'soon'],
    'capacity 59 is weak' => ['100 cycles, condition Normal, max capacity 59%', 'soon'],
    'cycles 699 is fine' => ['699 cycles, condition Normal, max capacity n/a', null],
    'cycles 700 is weak' => ['700 cycles, condition Normal, max capacity n/a', 'soon'],
    'cycles 1000 is weak' => ['1000 cycles, condition Normal, max capacity n/a', 'soon'],
    'a Windows battery line' => ['312 cycles, 55% of design capacity', 'soon'],
    'a Windows battery line that is fine' => ['312 cycles, 85% of design capacity', null],
    'no battery' => ['none', null],
    'unreadable battery' => ['n/a cycles, condition n/a, max capacity n/a', null],
]);

it('says which battery reason it is', function () {
    expect(attentionReasons(attentionMarkdown(['Battery' => '100 cycles, condition Normal, max capacity 79%'])))->toBe(['The battery is getting weak']);
});

it('lists a replace verdict as urgent', function () {
    $row = attentionRow(attentionMarkdown(['Model' => 'Mac (iMac18,1)', 'macOS' => '10.14.6', 'Memory' => '2 GB', 'Free disk' => '12%']), [ATTENTION_UNSUPPORTED]);

    expect($row['level'])->toBe('urgent')
        ->and(array_column($row['reasons'], 'text'))->toBe(['The operating system no longer gets security updates', 'The scan says this machine is worth replacing']);
});

it('lists a repair verdict as soon', function () {
    $row = attentionRow(attentionMarkdown(['Memory' => '2 GB', 'Free disk' => '12%']));

    expect($row['level'])->toBe('soon')
        ->and($row['reasons'])->toBe([['level' => 'soon', 'text' => ATTENTION_REPAIR]]);
});

it('does not list a keep verdict or one without enough data', function () {
    $user = User::factory()->create();
    attentionMachine($user, attentionMarkdown(), name: 'Keep');
    attentionMachine($user, "# Checkup\n\n- Format: 1\n", name: 'Sparse');

    expect(attentionFor($user)['rows'])->toBe([]);
});

it('lists high severity flags as urgent with the plain headline', function () {
    $reasons = attentionReasons(attentionMarkdown(), ['Defender real-time protection is OFF.', 'Software updates are pending.', 'A restart is pending.']);

    expect($reasons)->toBe(['No antivirus is protecting this PC']);
});

it('does not count the battery flag or the unsupported flag twice', function () {
    $row = attentionRow(attentionMarkdown(['Battery' => '100 cycles, condition Poor, max capacity 95%']), ['Battery condition is Poor.', ATTENTION_UNSUPPORTED]);

    expect(array_column($row['reasons'], 'text'))->toBe(['The operating system no longer gets security updates', 'The battery needs service or replacing'])
        ->and($row['reason_count'])->toBe(3);
});

it('shows at most the two most severe reasons but counts them all', function () {
    $row = attentionRow(attentionMarkdown(['Free disk' => '9%', 'Battery' => '100 cycles, condition Poor, max capacity 95%']), ['Defender real-time protection is OFF.', 'Gatekeeper is DISABLED.']);

    expect($row['reasons'])->toHaveCount(2)
        ->and(array_column($row['reasons'], 'level'))->toBe(['urgent', 'urgent'])
        ->and($row['reason_count'])->toBe(5);
});

it('puts a soon reason behind urgent ones', function () {
    $row = attentionRow(attentionMarkdown(['Free disk' => '9%']), ['Defender real-time protection is OFF.']);

    expect($row['reasons'])->toBe([
        ['level' => 'urgent', 'text' => 'No antivirus is protecting this PC'],
        ['level' => 'soon', 'text' => 'Only 9% of the drive is free'],
    ]);
});

it('rates the age of the last scan at the six month boundary', function (int $seconds, bool $listed) {
    $user = User::factory()->create();
    attentionMachine($user, scanned: CarbonImmutable::parse(ATTENTION_NOW)->subDays(NeedsAttention::RESCAN_AFTER_DAYS)->subSeconds($seconds));
    $rows = attentionFor($user)['rows'];

    expect($rows !== [])->toBe($listed);

    if ($listed) {
        expect($rows[0]['level'])->toBe('soon')->and($rows[0]['reasons'][0]['text'])->toBe('Last scanned more than 6 months ago');
    }
})->with([
    'a day short of the limit' => [-86400, false],
    'a second short of the limit' => [-1, false],
    'exactly at the limit' => [0, false],
    'a second past the limit' => [1, true],
    'a year past the limit' => [365 * 86400, true],
]);

it('lists a machine that was never scanned only once it is older than a week', function (int $seconds, bool $listed) {
    $user = User::factory()->create();
    attentionMachine($user, false, created: CarbonImmutable::parse(ATTENTION_NOW)->subDays(NeedsAttention::NEW_MACHINE_GRACE_DAYS)->subSeconds($seconds));
    $rows = attentionFor($user)['rows'];

    expect($rows !== [])->toBe($listed);

    if ($listed) {
        expect($rows[0]['level'])->toBe('soon')->and($rows[0]['reasons'])->toBe([['level' => 'soon', 'text' => 'No scan yet']]);
    }
})->with([
    'created today' => [-7 * 86400, false],
    'one second short of a week' => [-1, false],
    'exactly a week old' => [0, false],
    'one second over a week' => [1, true],
    'a year old' => [365 * 86400, true],
]);

it('survives odd and partial reports', function (string $markdown, array $flags) {
    $user = User::factory()->create();
    attentionMachine($user, $markdown, $flags);

    expect(attentionFor($user)['total'])->toBeInt();
})->with([
    'empty markdown' => ['', []],
    'only a title' => ['# Checkup', []],
    'unknown operating system' => [attentionMarkdown(['macOS' => 'unknown', 'Windows' => 'n/a']), []],
    'control characters' => ["# Checkup\n\n- Free disk: \x00\x07%\n- Battery: \x1b[31m cycles, \n", ['Defender real-time protection is OFF.']],
    'unicode' => [attentionMarkdown(['Model' => 'Mac mini (日本語)', 'Free disk' => '４%']), ['未知のフラグ']],
    'an unknown flag only' => [attentionMarkdown(), ['Something nobody has written a rule for.']],
    'decimal comma' => [attentionMarkdown(['Free disk' => '4,5%']), []],
]);

it('does not list an unknown low severity flag', function () {
    expect(attentionReasons(attentionMarkdown(), ['Something nobody has written a rule for.']))->toBe([]);
});

it('sorts urgent first, then by number of reasons, then by name', function () {
    $user = User::factory()->create();
    attentionMachine($user, attentionMarkdown(['Free disk' => '9%']), name: 'Zed soon');
    attentionMachine($user, attentionMarkdown(['Free disk' => '9%', 'Battery' => '800 cycles, condition Normal, max capacity 95%']), name: 'Mid soon two');
    attentionMachine($user, attentionMarkdown(['Free disk' => '9%']), name: 'Alpha soon');
    attentionMachine($user, attentionMarkdown(['Free disk' => '4%']), name: 'Urgent one');
    attentionMachine($user, attentionMarkdown(['Free disk' => '4%', 'Battery' => '100 cycles, condition Poor, max capacity 95%']), name: 'urgent two');
    attentionMachine($user, attentionMarkdown(['Free disk' => '4%']), name: 'Another urgent one');

    $found = attentionFor($user);

    expect(array_column($found['rows'], 'name'))->toBe(['urgent two', 'Another urgent one', 'Urgent one', 'Mid soon two', 'Alpha soon', 'Zed soon'])
        ->and($found['urgent'])->toBe(3)
        ->and($found['total'])->toBe(6);
});

it('puts the client under the machine', function () {
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create(['name' => 'Bakkerij Smit']);
    $machine = attentionMachine($user, attentionMarkdown(['Free disk' => '4%']));
    $machine->forceFill(['client_id' => $client->id])->save();
    attentionMachine($user, attentionMarkdown(['Free disk' => '4%']), name: 'Loner');

    $rows = array_column(attentionFor($user)['rows'], null, 'name');

    expect($rows['Alpha']['client'])->toBe(['id' => $client->id, 'name' => 'Bakkerij Smit'])
        ->and($rows['Loner']['client'])->toBeNull();
});

it('names an unnamed machine after its model', function () {
    $user = User::factory()->create();
    $machine = attentionMachine($user, attentionMarkdown(['Free disk' => '4%']));
    $machine->forceFill(['name' => null, 'model' => 'iMac (iMac18,1)'])->save();
    $second = attentionMachine($user, attentionMarkdown(['Free disk' => '4%']), name: 'x');
    $second->forceFill(['name' => null, 'model' => null])->save();

    expect(array_column(attentionFor($user)['rows'], 'name'))->toBe(['iMac (iMac18,1)', 'Unnamed machine']);
});

it('never shows another account or deleted data', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    attentionMachine($other, attentionMarkdown(['Free disk' => '1%']), name: 'Theirs');
    attentionMachine($user, attentionMarkdown(['Free disk' => '1%']), name: 'Deleted machine')->delete();
    attentionMachine($user, attentionMarkdown(['Free disk' => '1%']), name: 'Mine');
    $reverted = attentionMachine($user, attentionMarkdown(['Free disk' => '1%']), name: 'Report deleted', scanned: CarbonImmutable::now()->subDays(2));
    Report::factory()->for($reverted)->create(['markdown' => attentionMarkdown(), 'created_at' => CarbonImmutable::now()->subDay()])->delete();

    $found = attentionFor($user);

    expect(array_column($found['rows'], 'name'))->toBe(['Mine', 'Report deleted'])
        ->and($found['total'])->toBe(2);
});

it('judges a machine by its newest report only', function () {
    $user = User::factory()->create();
    $machine = attentionMachine($user, attentionMarkdown(['Free disk' => '1%']), scanned: CarbonImmutable::now()->subDays(10));
    Report::factory()->for($machine)->create(['markdown' => attentionMarkdown(), 'created_at' => CarbonImmutable::now()->subDay()]);

    expect(attentionFor($user)['rows'])->toBe([]);
});

it('caps the list at twenty unless asked for all', function () {
    $user = User::factory()->create();
    foreach (range(1, 23) as $number) {
        attentionMachine($user, attentionMarkdown(['Free disk' => '4%']), name: sprintf('Machine %02d', $number));
    }

    $capped = attentionFor($user);
    $all = attentionFor($user, '?attention=all');

    expect($capped['rows'])->toHaveCount(20)
        ->and($capped['total'])->toBe(23)
        ->and($capped['showing_all'])->toBeFalse()
        ->and($all['rows'])->toHaveCount(23)
        ->and($all['showing_all'])->toBeTrue()
        ->and(attentionFor($user, '?attention=bogus')['rows'])->toHaveCount(20);
});

it('does not query per machine', function () {
    $user = User::factory()->create();
    foreach (range(1, 12) as $number) {
        attentionMachine($user, attentionMarkdown(['Free disk' => '4%']), name: "M{$number}");
    }

    DB::enableQueryLog();
    attentionFor($user);
    $few = count(DB::getQueryLog());

    foreach (range(13, 30) as $number) {
        attentionMachine($user, attentionMarkdown(['Free disk' => '4%']), name: "M{$number}");
    }

    DB::flushQueryLog();
    attentionFor($user);

    expect(count(DB::getQueryLog()))->toBe($few);
});

it('is only for signed in owners', function () {
    $this->get('/machines')->assertRedirect(route('login'));
});

it('never reaches the share page', function () {
    $client = Client::factory()->withShareLink()->create();
    Machine::factory()->for($client->user)->create(['client_id' => $client->id]);

    $this->get("/c/{$client->share_token}")->assertOk()->assertInertia(fn ($page) => $page->missing('needsAttention'));
});

it('rates staleness with the shared freshness rule', function () {
    $report = new Report;
    $report->created_at = CarbonImmutable::now()->subDays(10);

    expect((new ReportFreshness)->isOlderThan($report, 9))->toBeTrue()
        ->and((new ReportFreshness)->isOlderThan($report, 10))->toBeFalse()
        ->and((new ReportFreshness)->isOlderThan(null, 1))->toBeFalse()
        ->and((new ReportFreshness)->isOlderThan(new Report, 1))->toBeFalse();
});
