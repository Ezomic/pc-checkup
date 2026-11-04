<?php

declare(strict_types=1);

use App\Models\Client;
use App\Models\Machine;
use App\Models\Report;
use App\Models\User;
use App\Services\Reports\ReportRedactor;

use function Pest\Laravel\actingAs;

const HFN_SECTION = "scan: complete\nseconds: 41\nlimit: 150\nformat: 2\nroot|Users|52428800\nroots_total|52428800\nhome|52428800\n"
    ."home|Quarterly Taxes ACME|40894464\nhome|Password Vault|2097152\nhome|token-cache|1048576\n"
    ."sub|Quarterly Taxes ACME|Invoices 2025|31457280\nsub|Quarterly Taxes ACME|Scans|6291456\n";

function hfnMarkdown(string $section = HFN_SECTION): string
{
    return macReportFixture()."\n## Storage breakdown\n\n```\n{$section}\n```\n";
}

describe('who sees the folder names', function () {
    it('gives the owner the named folders in the camelCase detailCards prop', function () {
        $user = User::factory()->create();
        $machine = Machine::factory()->for($user)->create(['device_type' => 'laptop']);
        Report::factory()->for($machine)->create(['markdown' => hfnMarkdown()]);

        actingAs($user)->get("/machines/{$machine->id}")
            ->assertOk()
            ->assertInertia(function ($page): void {
                $card = collect($page->toArray()['props']['detailCards'])->firstWhere('id', 'storage-breakdown');
                $group = collect($card['bars'])->firstWhere('title', 'Biggest folders in your user folder');

                expect(array_column($group['items'], 'label'))->toBe(['Quarterly Taxes ACME', 'Password Vault', 'token-cache'])
                    ->and($group['items'][0]['inside']['title'])->toBe('Inside Quarterly Taxes ACME')
                    ->and(array_column($group['items'][0]['inside']['items'], 'label'))->toBe(['Invoices 2025', 'Scans'])
                    ->and($card['summary'])->toContain('the biggest part is Quarterly Taxes ACME (39 GB)')
                    ->and($card['notes'])->toContain('The names and sizes of the biggest folders in your user folder were recorded so this card can say what takes the space. Only you see them: they are not on the pages you share with clients.');
            });
    });

    it('never gives the names, the card or the raw report to a share page', function () {
        $client = Client::factory()->withShareLink()->create();
        $machine = Machine::factory()->for($client->user)->create(['client_id' => $client->id, 'device_type' => 'laptop']);
        Report::factory()->for($machine)->create(['markdown' => hfnMarkdown(), 'flags' => ['FileVault is OFF.']]);

        foreach (["/c/{$client->share_token}", "/c/{$client->share_token}/machines/{$machine->id}"] as $path) {
            $response = $this->get($path)->assertOk();
            $props = '';
            $response->assertInertia(function ($page) use (&$props): void {
                $props = (string) json_encode($page->toArray()['props']);
            });

            foreach ([$props, (string) $response->getContent()] as $body) {
                foreach (['Quarterly Taxes', 'Invoices 2025', 'Password Vault', 'token-cache', 'Storage breakdown', 'storage-breakdown', 'detailCards', 'home|', 'sub|'] as $hidden) {
                    expect($body)->not->toContain($hidden);
                }
            }
        }
    });

    it('keeps the names off the printable summary page', function () {
        $user = User::factory()->create();
        $machine = Machine::factory()->for($user)->create();
        Report::factory()->for($machine)->create(['markdown' => hfnMarkdown()]);

        $response = actingAs($user)->get("/machines/{$machine->id}/summary")->assertOk();

        expect((string) $response->getContent())->not->toContain('Quarterly Taxes')->not->toContain('Password Vault');
    });

    it('does not show another account the names', function () {
        $machine = Machine::factory()->for(User::factory()->create())->create();
        Report::factory()->for($machine)->create(['markdown' => hfnMarkdown()]);

        actingAs(User::factory()->create())->get("/machines/{$machine->id}")->assertForbidden();
    });
});

describe('the redactor and folder names', function () {
    it('keeps a folder name that mentions a secret word, because the rules look at keys and not at names', function (string $line) {
        expect((new ReportRedactor)->redact("before\n{$line}\nafter"))->toBe("before\n{$line}\nafter");
    })->with([
        'password' => ['home|Password Vault|2097152'],
        'token' => ['home|token-cache|1048576'],
        'secret inside a folder' => ['sub|Keys|my secret: notes|5'],
        'mobileme' => ['sub|Library|MobileMe|5'],
        'a name with a colon' => ['home|token: x|5'],
    ]);

    it('still removes a line that holds a binary blob or a property list in its name', function (string $line) {
        expect((new ReportRedactor)->redact("before\n{$line}\nafter"))->toBe("before\n[removed]\nafter");
    })->with([
        'bplist' => ['home|bplist00stuff|5'],
        'escaped bytes' => ['sub|Library|%41%42%43%44%45%46%47%48|5'],
    ]);

    it('does not crash on a folder name made to be hard on the patterns', function () {
        $name = str_repeat('token', 20000).str_repeat('=', 20000);
        $redacted = (new ReportRedactor)->redact("home|{$name}|5\nsub|".str_repeat('a:', 50000).'|5');

        expect($redacted)->toContain('home|token')->and(strlen($redacted))->toBeGreaterThan(100000);
    });

    it('stores a folder named after a secret word through the import, and shows it to the owner only', function () {
        $user = User::factory()->create();

        actingAs($user)->post('/imports', ['text' => hfnMarkdown()])->assertRedirect('/machines');

        $stored = Report::query()->sole()->markdown;

        expect($stored)->toContain('home|Password Vault|2097152')->toContain('home|token-cache|1048576');
    });
});
