<?php

declare(strict_types=1);

use App\Models\Client;
use App\Models\Machine;
use App\Models\Report;
use App\Models\User;
use App\Services\Reports\ReportDetails;
use App\Services\Reports\ReportRedactor;

use function Pest\Laravel\actingAs;

const UB_APPS = "scan: complete\nseconds: 20\nlimit: 45\nformat: 1\nscanned: 2026-11-06\n"
    ."app|Old Design Suite|5242880|2023-02-01\napp|Forgotten Game Zorgvuldig|2097152|unknown\n";
const UB_BACKUPS = "format: 1\nscanned: 2026-11-06\ntimemachine: set up\ntimemachine-last: 2026-08-01\nicloud-folders: none\n";

function ubOldReport(): string
{
    return (string) preg_replace('/\n## (Apps you may not need|Backup freshness)\n.*?```\n.*?```\n/s', "\n", macReportFixture());
}

function ubMarkdown(string $apps = UB_APPS, string $backups = UB_BACKUPS): string
{
    return ubOldReport()."\n## Apps you may not need\n\n```\n{$apps}```\n\n## Backup freshness\n\n```\n{$backups}```\n";
}

/**
 * @return array<string, array<string, mixed>>
 */
function ubOwnerCards(string $markdown): array
{
    $user = User::factory()->create();
    $machine = Machine::factory()->for($user)->create(['device_type' => 'laptop']);
    Report::factory()->for($machine)->create(['markdown' => $markdown]);
    $cards = [];

    actingAs($user)->get("/machines/{$machine->id}")->assertOk()->assertInertia(function ($page) use (&$cards): void {
        $cards = array_column($page->toArray()['props']['detailCards'], null, 'id');
    });

    return $cards;
}

describe('who sees the cards', function () {
    it('gives the owner both cards in the camelCase detailCards prop', function () {
        $cards = ubOwnerCards(ubMarkdown());

        expect($cards['unused-apps']['title'])->toBe('Apps you may not need')
            ->and($cards['unused-apps']['summary'])->toBe('About 5 GB in apps not opened for a year')
            ->and($cards['backups']['title'])->toBe('Backups')
            ->and($cards['backups']['rows'][0])->toBe(['label' => 'Backup', 'value' => 'Last backup 97 days ago', 'tone' => 'bad']);
    });

    it('never gives the cards, the names or the raw report to a share page', function () {
        $client = Client::factory()->withShareLink()->create();
        $machine = Machine::factory()->for($client->user)->create(['client_id' => $client->id, 'device_type' => 'laptop']);
        Report::factory()->for($machine)->create(['markdown' => ubMarkdown(), 'flags' => ['FileVault is OFF.']]);

        foreach (["/c/{$client->share_token}", "/c/{$client->share_token}/machines/{$machine->id}"] as $path) {
            $response = $this->get($path)->assertOk();
            $props = '';
            $response->assertInertia(function ($page) use (&$props): void {
                $props = (string) json_encode($page->toArray()['props']);
            });

            foreach ([$props, (string) $response->getContent()] as $body) {
                foreach (['Old Design Suite', 'Forgotten Game', 'Apps you may not need', 'unused-apps', 'Backup freshness', 'Last backup', 'timemachine', 'detailCards', 'app|', 'icloud-folders'] as $hidden) {
                    expect($body)->not->toContain($hidden);
                }
            }
        }
    });

    it('keeps the names off the printable summary page', function () {
        $user = User::factory()->create();
        $machine = Machine::factory()->for($user)->create();
        Report::factory()->for($machine)->create(['markdown' => ubMarkdown()]);

        $body = (string) actingAs($user)->get("/machines/{$machine->id}/summary")->assertOk()->getContent();

        expect($body)->not->toContain('Old Design Suite')->not->toContain('Apps you may not need')->not->toContain('Last backup');
    });

    it('does not show another account the cards', function () {
        $machine = Machine::factory()->for(User::factory()->create())->create();
        Report::factory()->for($machine)->create(['markdown' => ubMarkdown()]);

        actingAs(User::factory()->create())->get("/machines/{$machine->id}")->assertForbidden();
    });

    it('shows no card for a report from before the steps existed', function () {
        expect(array_keys(ubOwnerCards(ubOldReport())))->not->toContain('unused-apps')->not->toContain('backups');
    });
});

describe('the redactor and app names', function () {
    it('keeps an app named after a secret word, because the rules look at keys and not at names', function (string $line) {
        expect((new ReportRedactor)->redact("before\n{$line}\nafter"))->toBe("before\n{$line}\nafter");
    })->with([
        'password manager' => ['app|1Password|2097152|2023-02-01'],
        'token app' => ['app|Token Generator|2097152|unknown'],
    ]);
});

describe('the fixtures', function () {
    it('gives both cards from the Mac fixture', function () {
        $cards = array_column(app(ReportDetails::class)->cards(macReportFixture(), [], 'laptop'), null, 'id');

        expect($cards['unused-apps']['summary'])->toBe('About 7.3 GB in apps not opened for a year')
            ->and(array_column($cards['unused-apps']['bars'][0]['items'], 'label'))->toBe(['Final Cut Old', 'Sketch Legacy', 'Old Notes Pro'])
            ->and(array_column($cards['backups']['rows'], 'value'))->toBe(['Last backup 3 days ago', 'Last backup 2026-11-03 (3 days ago)', 'Desktop, Documents']);
    });

    it('reads the Dutch Mac fixture with decimal commas', function () {
        $cards = array_column(app(ReportDetails::class)->cards((string) file_get_contents(__DIR__.'/../Fixtures/mac-checkup-dutch-air.md'), [], 'laptop'), null, 'id');

        expect(array_column($cards['unused-apps']['bars'][0]['items'], 'value'))->toBe(['2.3 GB', '650 MB'])
            ->and($cards['backups']['rows'][0])->toBe(['label' => 'Backup', 'value' => 'No backup found', 'tone' => 'bad']);
    });

    it('reads the Windows fixture', function () {
        $cards = array_column(app(ReportDetails::class)->cards(windowsLaptopFixture(), [], 'laptop'), null, 'id');

        expect($cards['unused-apps']['bars'][1]['items'][0]['detail'])->toBe('Last use unknown')
            ->and(array_column($cards['backups']['rows'], 'label'))->toBe(['Backup', 'File History', 'OneDrive folder backup', 'Last restore point'])
            ->and($cards['backups']['rows'][0]['tone'])->toBe('bad');
    });
});
