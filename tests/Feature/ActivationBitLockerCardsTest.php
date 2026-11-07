<?php

declare(strict_types=1);

use App\Models\Client;
use App\Models\Machine;
use App\Models\Report;
use App\Models\User;
use App\Services\Reports\ReportActivationBitLocker;
use App\Services\Reports\ReportDetails;
use App\Services\Reports\ReportSections;
use Carbon\CarbonImmutable;

use function Pest\Laravel\actingAs;

beforeEach(fn () => CarbonImmutable::setTestNow('2026-11-07 12:00:00'));
afterEach(fn () => CarbonImmutable::setTestNow());

function abSection(string $body): string
{
    return "\n## Activation and BitLocker\n\n```\n{$body}\n```\n";
}

function abWindows(string $activation = 'licensed', string $drives = "bitlocker: complete\ndrive|C:|on"): string
{
    return windowsLaptopFixture().abSection("format: 1\nactivation: {$activation}\n{$drives}");
}

/**
 * @return array<string, mixed>|null
 */
function abCard(string $markdown): ?array
{
    return array_column(app(ReportDetails::class)->cards($markdown, [], 'laptop'), null, 'id')['activation-bitlocker'] ?? null;
}

/**
 * @return array<string, array{value: string, tone: string}>
 */
function abRows(?array $card): array
{
    $rows = [];

    foreach ($card['rows'] ?? [] as $row) {
        $rows[$row['label']] = ['value' => $row['value'], 'tone' => $row['tone']];
    }

    return $rows;
}

/**
 * @return list<string>
 */
function abReasonTexts(string $markdown): array
{
    return array_map(fn (array $reason): string => "{$reason['level']}: {$reason['text']}", (new ReportActivationBitLocker)->reasons(ReportSections::parse($markdown)));
}

describe('the activation and BitLocker card', function () {
    it('shows an activated Windows and a protected drive with the recovery key reminder', function () {
        $card = abCard(abWindows());

        expect($card['title'])->toBe('Activation and BitLocker')
            ->and(abRows($card))->toBe([
                'Windows activation' => ['value' => 'Activated', 'tone' => 'good'],
                'Drive C:' => ['value' => 'BitLocker on', 'tone' => 'good'],
                'Recovery key' => ['value' => ReportActivationBitLocker::RECOVERY_KEY_REMINDER, 'tone' => 'neutral'],
            ])
            ->and($card['list'])->toBeNull()
            ->and($card['notes'])->toBe([
                'Only whether Windows is activated was read, never the product key. BitLocker recovery keys are never read.',
                'Only you see this: it is not on the pages you share with clients.',
            ]);
    });

    it('says where to save the recovery key in plain words', function () {
        expect(ReportActivationBitLocker::RECOVERY_KEY_REMINDER)->toContain('Microsoft account')->toContain('printed');
    });

    it('turns each licence state into plain words', function (string $state, string $value, string $tone) {
        expect(abRows(abCard(abWindows($state)))['Windows activation'])->toBe(['value' => $value, 'tone' => $tone]);
    })->with([
        'licensed' => ['licensed', 'Activated', 'good'],
        'notification' => ['notification', 'Not activated. Windows shows reminders and locks some personal settings. Activate it in Settings > System > Activation.', 'bad'],
        'unlicensed' => ['unlicensed', 'Not activated. Activate it in Settings > System > Activation.', 'bad'],
        'grace period' => ['grace period', 'Not activated yet, it runs on a grace period. Activate it in Settings > System > Activation before the period ends.', 'bad'],
        'non-genuine' => ['non-genuine grace period', 'Not activated: Windows reports a licence that is not genuine. Activate it with a genuine licence in Settings > System > Activation.', 'bad'],
        'upper case' => ['LICENSED', 'Activated', 'good'],
    ]);

    it('turns each drive state into plain words', function (string $state, string $value, string $tone) {
        expect(abRows(abCard(abWindows('licensed', "bitlocker: complete\ndrive|D:|{$state}")))['Drive D:'])->toBe(['value' => $value, 'tone' => $tone]);
    })->with([
        'on' => ['on', 'BitLocker on', 'good'],
        'locked' => ['locked', 'BitLocker on (locked)', 'good'],
        'off' => ['off', 'BitLocker off', 'neutral'],
        'encrypting' => ['encrypting', 'BitLocker is being turned on (encrypting)', 'neutral'],
        'decrypting' => ['decrypting', 'BitLocker is being turned off (decrypting)', 'neutral'],
        'suspended' => ['suspended', 'BitLocker suspended: the drive is encrypted but not protected. Resume protection in Control Panel > BitLocker Drive Encryption.', 'bad'],
        'waiting' => ['waiting', 'Encrypted, but BitLocker is waiting to be turned on, so it is not protected yet. Turn on BitLocker or device encryption to finish.', 'neutral'],
        'upper case' => ['ON', 'BitLocker on', 'good'],
    ]);

    it('reminds about the recovery key only while a drive is or stays encrypted', function (string $state, bool $reminded) {
        expect(array_key_exists('Recovery key', abRows(abCard(abWindows('licensed', "bitlocker: complete\ndrive|C:|{$state}")))))->toBe($reminded);
    })->with([
        'on' => ['on', true],
        'locked' => ['locked', true],
        'suspended' => ['suspended', true],
        'encrypting' => ['encrypting', true],
        'off' => ['off', false],
        'decrypting' => ['decrypting', false],
        'waiting' => ['waiting', false],
    ]);

    it('lists every fixed drive once, in the order of the scan', function () {
        $card = abCard(abWindows('licensed', "bitlocker: complete\ndrive|C:|on\ndrive|D:|off\ndrive|c:|off\ndrive|E:|suspended"));

        expect(array_column($card['rows'], 'label'))->toBe(['Windows activation', 'Drive C:', 'Drive D:', 'Drive E:', 'Recovery key'])
            ->and(abRows($card)['Drive C:']['value'])->toBe('BitLocker on');
    });

    it('drops drive lines it cannot trust', function (string $line) {
        expect(array_keys(abRows(abCard(abWindows('licensed', "bitlocker: complete\n{$line}")))))->toBe(['Windows activation']);
    })->with([
        'could not check' => 'drive|C:|could not check',
        'unknown state' => 'drive|C:|maybe',
        'empty state' => 'drive|C:|',
        'number state' => 'drive|C:|5',
        'two letters' => 'drive|CC:|on',
        'digit letter' => 'drive|1:|on',
        'no colon' => 'drive|C|on',
        'path' => 'drive|C:\\|on',
        'unicode letter' => "drive|\u{c7}:|on",
        'control character' => "drive|C:|o\u{7}n",
        'too many parts' => 'drive|C:|on|extra',
        'not a drive' => 'disk|C:|on',
        'recovery key shape' => 'drive|C:|123456-123456-123456-123456-123456-123456-123456-123456',
    ]);

    it('hides the activation row when it cannot be read', function (string $activation) {
        expect(array_keys(abRows(abCard(abWindows($activation)))))->toBe(['Drive C:', 'Recovery key']);
    })->with([
        'could not check' => 'could not check',
        'empty' => '',
        'unknown' => 'perhaps',
        'raw code' => '1',
        'product key shape' => 'XXXXX-XXXXX-XXXXX-XXXXX-3V66T',
        'unicode' => "licens\u{e9}d",
        'control character' => "licensed\u{7}",
    ]);

    it('keeps the activation row when BitLocker could not be read, and ignores drive lines then', function () {
        expect(abRows(abCard(abWindows('notification', "bitlocker: could not check\ndrive|C:|suspended"))))->toBe([
            'Windows activation' => ['value' => 'Not activated. Windows shows reminders and locks some personal settings. Activate it in Settings > System > Activation.', 'tone' => 'bad'],
        ]);
    });

    it('is hidden when nothing could be read', function (string $body) {
        expect(abCard(windowsLaptopFixture().abSection($body)))->toBeNull();
    })->with([
        'nothing readable' => "format: 1\nactivation: could not check\nbitlocker: could not check",
        'no drives' => "format: 1\nactivation: could not check\nbitlocker: complete",
        'empty' => '',
        'junk' => "\u{0}\u{1}garbage|||",
    ]);

    it('is hidden on an older report and on a Mac', function () {
        expect(abCard(windowsLaptopFixture()))->toBeNull()
            ->and(abCard((string) file_get_contents(base_path('tests/Fixtures/windows-checkup-desktop.md'))))->toBeNull()
            ->and(abCard(macReportFixture()))->toBeNull()
            ->and(abCard(''))->toBeNull();
    });

    it('survives a huge section', function () {
        $lines = implode("\n", array_fill(0, 50000, 'drive|C:|on'));
        $card = abCard(windowsLaptopFixture().abSection("format: 1\nactivation: licensed\nbitlocker: complete\n{$lines}"));

        expect(array_column($card['rows'], 'label'))->toBe(['Windows activation', 'Drive C:', 'Recovery key']);
    });

    it('comes after the existing cards and before the verdict', function () {
        $ids = array_flip(array_column(app(ReportDetails::class)->cards(abWindows(), [], 'laptop'), 'id'));

        expect($ids['activation-bitlocker'])->toBeGreaterThan($ids['updates'])
            ->and($ids['activation-bitlocker'])->toBeLessThan($ids['verdict']);
    });
});

describe('needs attention reasons', function () {
    it('says Windows is not activated for every state that is not licensed', function (string $state) {
        expect(abReasonTexts(abWindows($state, "bitlocker: complete\ndrive|C:|off")))->toBe(['soon: Windows is not activated']);
    })->with(['notification', 'unlicensed', 'grace period', 'non-genuine grace period']);

    it('names every suspended drive', function () {
        expect(abReasonTexts(abWindows('licensed', "bitlocker: complete\ndrive|C:|suspended")))->toBe(['soon: BitLocker is suspended on C:'])
            ->and(abReasonTexts(abWindows('licensed', "bitlocker: complete\ndrive|C:|suspended\ndrive|D:|on\ndrive|E:|suspended\ndrive|F:|suspended")))->toBe(['soon: BitLocker is suspended on C:, E: and F:']);
    });

    it('gives both reasons together', function () {
        expect(abReasonTexts(abWindows('unlicensed', "bitlocker: complete\ndrive|C:|suspended")))->toBe(['soon: Windows is not activated', 'soon: BitLocker is suspended on C:']);
    });

    it('stays quiet for an activated Windows, drives that are not suspended, or what it cannot read', function (string $markdown) {
        expect(abReasonTexts($markdown))->toBe([]);
    })->with([
        'all fine' => fn () => abWindows(),
        'off, waiting, decrypting' => fn () => abWindows('licensed', "bitlocker: complete\ndrive|C:|off\ndrive|D:|waiting\ndrive|E:|decrypting\ndrive|F:|encrypting\ndrive|G:|locked"),
        'unreadable activation' => fn () => abWindows('could not check'),
        'unreadable BitLocker' => fn () => abWindows('licensed', "bitlocker: could not check\ndrive|C:|suspended"),
        'older report' => fn () => windowsLaptopFixture(),
        'mac' => fn () => macReportFixture(),
    ]);

    it('shows the reasons in the needs attention overview', function () {
        $user = User::factory()->create();
        $machine = Machine::factory()->for($user)->create(['created_at' => CarbonImmutable::now()->subYear()]);
        $markdown = "# Windows checkup: PC\n\n- Format: 1\n- OS: Windows\n".abSection("format: 1\nactivation: notification\nbitlocker: complete\ndrive|C:|suspended");
        Report::factory()->for($machine)->create(['markdown' => $markdown, 'flags' => ['Windows is not activated (status: Notification).'], 'created_at' => CarbonImmutable::now()->subDay()]);
        $found = [];

        actingAs($user)->get('/machines?attention=all')->assertOk()->assertInertia(function ($page) use (&$found): void {
            $found = $page->toArray()['props']['needsAttention']['rows'][0]['reasons'] ?? [];
        });

        expect($found)->toBe([
            ['level' => 'soon', 'text' => 'Windows is not activated'],
            ['level' => 'soon', 'text' => 'BitLocker is suspended on C:'],
        ]);
    });
});

describe('who sees the card', function () {
    function abMarkdown(): string
    {
        return abWindows('notification', "bitlocker: complete\ndrive|C:|suspended\ndrive|Q:|on");
    }

    it('gives the owner the card in the camelCase detailCards prop', function () {
        $user = User::factory()->create();
        $machine = Machine::factory()->for($user)->create(['device_type' => 'laptop']);
        Report::factory()->for($machine)->create(['markdown' => abMarkdown()]);
        $cards = [];

        actingAs($user)->get("/machines/{$machine->id}")->assertOk()->assertInertia(function ($page) use (&$cards): void {
            $cards = array_column($page->toArray()['props']['detailCards'], null, 'id');
        });

        expect(abRows($cards['activation-bitlocker'] ?? null))->toHaveKeys(['Windows activation', 'Drive C:', 'Drive Q:', 'Recovery key']);
    });

    it('never gives the card, its rows or the raw section to a share page', function () {
        $client = Client::factory()->withShareLink()->create();
        $machine = Machine::factory()->for($client->user)->create(['client_id' => $client->id, 'device_type' => 'laptop']);
        Report::factory()->for($machine)->create(['markdown' => abMarkdown()]);

        foreach (["/c/{$client->share_token}", "/c/{$client->share_token}/machines/{$machine->id}"] as $path) {
            $response = $this->get($path)->assertOk();
            $props = '';
            $response->assertInertia(function ($page) use (&$props): void {
                $props = (string) json_encode($page->toArray()['props']);
            });

            foreach ([$props, (string) $response->getContent()] as $body) {
                foreach (['activation-bitlocker', 'Activation and BitLocker', 'Drive Q:', 'drive|Q:', 'Recovery key', 'BitLocker suspended', 'detailCards'] as $hidden) {
                    expect($body)->not->toContain($hidden);
                }
            }
        }
    });

    it('does not show one account the card of another', function () {
        $mine = User::factory()->create();
        $theirs = Machine::factory()->for(User::factory()->create())->create(['device_type' => 'laptop']);
        Report::factory()->for($theirs)->create(['markdown' => abMarkdown()]);

        actingAs($mine)->get("/machines/{$theirs->id}")->assertForbidden();
    });

    it('does not put another account\'s machine in my needs attention list', function () {
        $mine = User::factory()->create();
        $theirs = Machine::factory()->for(User::factory()->create())->create(['created_at' => CarbonImmutable::now()->subYear()]);
        Report::factory()->for($theirs)->create(['markdown' => abMarkdown(), 'flags' => []]);
        $rows = null;

        actingAs($mine)->get('/machines?attention=all')->assertOk()->assertInertia(function ($page) use (&$rows): void {
            $rows = $page->toArray()['props']['needsAttention']['rows'] ?? null;
        });

        expect($rows)->toBe([]);
    });
});
