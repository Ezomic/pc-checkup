<?php

declare(strict_types=1);

use App\Enums\ShareLocale;
use App\Models\Client;
use App\Models\Machine;
use App\Models\Report;
use App\Models\User;

use function Pest\Laravel\actingAs;

const SU_MARKDOWN = "# Mac checkup: Test Air\n\n- Format: 1\n- OS: macOS\n- Free disk: 11%\n- Run as administrator: no, sections that need sudo say so\n\n"
    ."## Disk usage\n\n```\nFilesystem      Size   Used  Avail Capacity iused ifree %iused  Mounted on\n"
    ."/dev/disk1s1   113Gi   97Gi   13Gi    88%  900001 9223372036853800000    0%   /\n"
    ."/dev/disk1s4   113Gi  2.0Gi   13Gi    14%       2 9223372036854775800    0%   /private/var/vm\n"
    ."/dev/disk1s3   113Gi  487Mi   13Gi     4%      30 9223372036854775700    0%   /Volumes/Recovery\n```\n\n"
    ."## Storage breakdown\n\n```\nscan: complete\nformat: 2\nroot|Users|52499440\nroot|Applications|13333212\nroots_total|81398076\n"
    ."missing|library/Mail\nmissing|library/Messages\nmissing|library/Safari\n```\n";

const SU_OWNER_WORDS = ['sudo', 'keeps for itself', 'swap, recovery', 'Not measured', 'admin rights', 'could not be read', 'keeps private'];

it('gives the owner the macOS volumes and the reason for the rest in the detail cards prop', function () {
    $user = User::factory()->create();
    $machine = Machine::factory()->for($user)->create(['device_type' => 'laptop']);
    Report::factory()->for($machine)->create(['markdown' => SU_MARKDOWN, 'flags' => [], 'ran_as_admin' => false]);
    $card = null;

    actingAs($user)->get("/machines/{$machine->id}")
        ->assertOk()
        ->assertInertia(function ($page) use (&$card): void {
            $card = collect($page->toArray()['props']['detailCards'])->firstWhere('id', 'storage-breakdown');
        });

    expect(array_slice($card['bars'][0]['items'], -2))->toBe([
        ['label' => 'Space macOS keeps for itself', 'value' => '2.5 GB', 'detail' => '2% of used space: separate parts of the drive for swap, recovery and startup files', 'share' => 5],
        ['label' => 'Not measured', 'value' => '20 GB', 'detail' => '20% of used space: system files that need admin rights, and folders macOS keeps private (Mail, Messages, Safari)', 'share' => 40],
    ])
        ->and($card['notes'])->toContain('Run the scan again with sudo to measure the system files too.');
});

it('shows a client none of the storage breakdown wording or the advice to run the scan with sudo', function (bool $machinePage) {
    $client = Client::factory()->withShareLink()->create(['locale' => ShareLocale::English]);
    $machine = Machine::factory()->for($client->user)->create(['client_id' => $client->id]);
    Report::factory()->for($machine)->create(['markdown' => SU_MARKDOWN, 'flags' => [], 'ran_as_admin' => false]);
    $path = "/c/{$client->share_token}".($machinePage ? "/machines/{$machine->id}" : '');
    $page = '';

    $response = $this->get($path)->assertOk()->assertInertia(function ($assertable) use (&$page) {
        $page = json_encode($assertable->toArray(), JSON_THROW_ON_ERROR);

        return $assertable;
    });

    foreach ([$page, (string) $response->getContent()] as $body) {
        foreach (SU_OWNER_WORDS as $word) {
            expect($body)->not->toContain($word);
        }
    }
})->with(['client page' => false, 'machine page' => true]);
