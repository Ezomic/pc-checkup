<?php

declare(strict_types=1);

use App\Models\Client;
use App\Models\Machine;
use App\Models\Report;
use App\Models\User;
use App\Services\Reports\ReportDetails;
use App\Services\Reports\ReportRedactor;

use function Pest\Laravel\actingAs;

const RS_SECTION = "scan: complete\nseconds: 12\nlimit: 60\nformat: 1\n"
    ."reclaim|trash|2097152|14\nreclaim|caches|5242880|63\nreclaim|backups|10485760|2\nsnapshots|3\n"
    ."file|Tax return 2025 Jansen.pdf|8388608|Documents|Belastingdienst\nfile|password-vault-export.zip|4194304|Archives|Secret Folder\n";

function rsOldReport(): string
{
    return (string) preg_replace('/\n## Space you can get back\n.*$/s', "\n", macReportFixture());
}

function rsMarkdown(string $section = RS_SECTION): string
{
    return rsOldReport()."\n## Space you can get back\n\n```\n{$section}\n```\n";
}

describe('who sees the card', function () {
    it('gives the owner the card in the camelCase detailCards prop', function () {
        $user = User::factory()->create();
        $machine = Machine::factory()->for($user)->create(['device_type' => 'laptop']);
        Report::factory()->for($machine)->create(['markdown' => rsMarkdown()]);

        actingAs($user)->get("/machines/{$machine->id}")
            ->assertOk()
            ->assertInertia(function ($page): void {
                $card = collect($page->toArray()['props']['detailCards'])->firstWhere('id', 'reclaimable');

                expect($card['title'])->toBe('Space you can get back')
                    ->and($card['summary'])->toBe('About 7 GB can usually be cleared.')
                    ->and(array_column($card['bars'][0]['items'], 'label'))->toBe(['Old iPhone and iPad backups', 'App caches', 'Trash'])
                    ->and(array_column($card['bars'][1]['items'], 'label'))->toBe(['Tax return 2025 Jansen.pdf', 'password-vault-export.zip']);
            });
    });

    it('never gives the card, the names or the raw report to a share page', function () {
        $client = Client::factory()->withShareLink()->create();
        $machine = Machine::factory()->for($client->user)->create(['client_id' => $client->id, 'device_type' => 'laptop']);
        Report::factory()->for($machine)->create(['markdown' => rsMarkdown(), 'flags' => ['FileVault is OFF.']]);

        foreach (["/c/{$client->share_token}", "/c/{$client->share_token}/machines/{$machine->id}"] as $path) {
            $response = $this->get($path)->assertOk();
            $props = '';
            $response->assertInertia(function ($page) use (&$props): void {
                $props = (string) json_encode($page->toArray()['props']);
            });

            foreach ([$props, (string) $response->getContent()] as $body) {
                foreach (['Tax return', 'Jansen', 'Belastingdienst', 'password-vault', 'Secret Folder', 'Space you can get back', 'reclaimable', 'detailCards', 'reclaim|', 'snapshots|', 'file|'] as $hidden) {
                    expect($body)->not->toContain($hidden);
                }
            }
        }
    });

    it('keeps the names off the printable summary page', function () {
        $user = User::factory()->create();
        $machine = Machine::factory()->for($user)->create();
        Report::factory()->for($machine)->create(['markdown' => rsMarkdown()]);

        $body = (string) actingAs($user)->get("/machines/{$machine->id}/summary")->assertOk()->getContent();

        expect($body)->not->toContain('Tax return')->not->toContain('password-vault')->not->toContain('Space you can get back');
    });

    it('does not show another account the card', function () {
        $machine = Machine::factory()->for(User::factory()->create())->create();
        Report::factory()->for($machine)->create(['markdown' => rsMarkdown()]);

        actingAs(User::factory()->create())->get("/machines/{$machine->id}")->assertForbidden();
    });

    it('shows no card for a report from before the step existed', function () {
        $user = User::factory()->create();
        $machine = Machine::factory()->for($user)->create(['device_type' => 'laptop']);
        Report::factory()->for($machine)->create(['markdown' => rsOldReport()]);
        $ids = [];

        actingAs($user)->get("/machines/{$machine->id}")->assertInertia(function ($page) use (&$ids): void {
            $ids = array_column($page->toArray()['props']['detailCards'], 'id');
        });

        expect($ids)->not->toContain('reclaimable');
    });
});

describe('the fixtures', function () {
    it('gives a card from the Mac fixture', function () {
        $card = collect(app(ReportDetails::class)->cards(macReportFixture(), [], 'laptop'))->firstWhere('id', 'reclaimable');

        expect($card['summary'])->toBe('About 6.4 GB can usually be cleared.')
            ->and(array_column($card['bars'][0]['items'], 'label'))->toBe(['Old iPhone and iPad backups', 'App caches', 'Installers in Downloads', 'Trash', 'Xcode build files'])
            ->and($card['bars'][1]['items'][0])->toBe(['label' => 'Wedding video 2019.mov', 'value' => '6 GB', 'detail' => 'Videos, in Movies', 'share' => 100])
            ->and($card['notes'])->toContain('4 local Time Machine snapshots are kept on this Mac. macOS frees them itself when it needs the space, so there is nothing for you to do.')
            ->and(implode(' ', $card['notes']))->not->toContain('time limit');
    });

    it('reads the Dutch Mac fixture with decimal commas', function () {
        $card = collect(app(ReportDetails::class)->cards((string) file_get_contents(__DIR__.'/../Fixtures/mac-checkup-dutch-air.md'), [], 'laptop'))->firstWhere('id', 'reclaimable');
        $places = array_column($card['bars'][0]['items'], null, 'label');

        expect($card['summary'])->toBe('About 6.6 GB can usually be cleared.')
            ->and($places['Old iPhone and iPad backups'])->toMatchArray(['value' => '12 GB'])
            ->and($places['App caches'])->toMatchArray(['value' => '4.5 GB'])
            ->and($places['App caches']['detail'])->toStartWith('1,234 items.')
            ->and($places['Trash']['detail'])->toStartWith('1,031 items.')
            ->and($places['Installers in Downloads']['value'])->toBe('881 MB')
            ->and($card['bars'][1]['items'][0])->toMatchArray(['label' => 'Vakantiefilm 2024.mov', 'value' => '7.5 GB'])
            ->and($card['bars'][1]['items'][1]['value'])->toBe('121 MB');
    });

    it('reads the Windows fixture, partial scan and all', function () {
        $card = collect(app(ReportDetails::class)->cards(windowsLaptopFixture(), [], 'laptop'))->firstWhere('id', 'reclaimable');
        $places = array_column($card['bars'][0]['items'], null, 'label');

        expect(array_keys($places))->toBe(['Previous Windows version (Windows.old)', 'Recycle Bin', 'Downloaded Windows updates', 'Temporary files', 'Installers in Downloads', 'Windows temporary files'])
            ->and($places['Recycle Bin']['detail'])->toStartWith('87 items. Empty the Recycle Bin.')
            ->and($places['Windows temporary files']['detail'])->toContain('Disk Cleanup')
            ->and($card['summary'])->toBe('About 22 GB can usually be cleared.')
            ->and($card['notes'][0])->toBe('The scan stopped at its time limit, so these sizes are a minimum and there may be more to clear.')
            ->and(array_column($card['bars'][1]['items'], 'label'))->toBe(['disk-image.vhdx', 'Game Recording.mp4', 'Setup.exe']);
    });
});

describe('the redactor and file names', function () {
    it('keeps a file name that mentions a secret word, because the rules look at keys and not at names', function (string $line) {
        expect((new ReportRedactor)->redact("before\n{$line}\nafter"))->toBe("before\n{$line}\nafter");
    })->with([
        'password' => ['file|password-vault-export.zip|4194304|Archives|Documents'],
        'token' => ['file|token: backup.zip|4194304|Archives|Documents'],
        'secret folder' => ['file|plan.pdf|4194304|Documents|secret'],
    ]);

    it('still removes a line that holds a binary blob or a property list in its name', function (string $line) {
        expect((new ReportRedactor)->redact("before\n{$line}\nafter"))->toBe("before\n[removed]\nafter");
    })->with([
        'bplist' => ['file|bplist00stuff.bin|4194304|Other|Documents'],
        'escaped bytes' => ['file|%41%42%43%44%45%46%47%48.bin|4194304|Other|Documents'],
    ]);

    it('stores a file named after a secret word through the import, and shows it to the owner only', function () {
        $user = User::factory()->create();

        actingAs($user)->post('/imports', ['text' => rsMarkdown()])->assertRedirect('/machines');

        expect(Report::query()->sole()->markdown)->toContain('file|password-vault-export.zip|4194304|Archives|Secret Folder');
    });
});
