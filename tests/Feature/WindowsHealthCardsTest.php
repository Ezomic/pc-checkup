<?php

declare(strict_types=1);

use App\Models\Client;
use App\Models\Machine;
use App\Models\Report;
use App\Models\User;
use Carbon\CarbonImmutable;

use function Pest\Laravel\actingAs;

beforeEach(fn () => CarbonImmutable::setTestNow('2026-11-06 12:00:00'));
afterEach(fn () => CarbonImmutable::setTestNow());

function wcFixture(string $name): string
{
    return (string) file_get_contents(base_path("tests/Fixtures/windows-health-{$name}.md"));
}

/**
 * @param  list<string>  $flags
 */
function wcMachine(User $user, string $markdown, array $flags = [], string $deviceType = 'laptop'): Machine
{
    $machine = Machine::factory()->for($user)->create(['device_type' => $deviceType, 'created_at' => CarbonImmutable::now()->subYear()]);
    Report::factory()->for($machine)->create(['markdown' => $markdown, 'flags' => $flags, 'created_at' => CarbonImmutable::now()->subDay()]);

    return $machine;
}

/**
 * @return array{total: int, urgent: int, rows: list<array<string, mixed>>, showing_all: bool}
 */
function wcAttention(User $user): array
{
    $found = [];

    actingAs($user)->get('/machines')->assertOk()->assertInertia(function ($page) use (&$found): void {
        $found = $page->toArray()['props']['needsAttention'];
    });

    return $found;
}

/**
 * @param  list<string>  $flags
 * @return array<string, mixed>|null
 */
function wcRow(string $markdown, array $flags = []): ?array
{
    $user = User::factory()->create();
    wcMachine($user, $markdown, $flags);

    return wcAttention($user)['rows'][0] ?? null;
}

describe('the owner page', function () {
    it('shows the drive and security rows in the storage and security cards', function () {
        $user = User::factory()->create();
        $machine = wcMachine($user, wcFixture('laptop-unhealthy'));
        $cards = [];

        actingAs($user)->get("/machines/{$machine->id}")->assertOk()->assertInertia(function ($page) use (&$cards): void {
            $cards = array_column($page->toArray()['props']['detailCards'], null, 'id');
        });

        $storage = array_column($cards['storage']['rows'], 'tone', 'label');
        $security = array_column($cards['security']['rows'], 'tone', 'label');

        expect($storage['Samsung SSD 970'])->toBe('bad')
            ->and($storage['Backup disk (USB)'])->toBe('neutral')
            ->and($security['Antivirus'])->toBe('bad')
            ->and($security['User Account Control'])->toBe('bad')
            ->and($security['Windows Firewall'])->toBe('bad');
    });

    it('keeps all of it off the share pages', function () {
        $client = Client::factory()->withShareLink()->create();
        $machine = Machine::factory()->for($client->user)->create(['client_id' => $client->id, 'device_type' => 'laptop']);
        Report::factory()->for($machine)->create(['markdown' => wcFixture('laptop-unhealthy')]);

        foreach (["/c/{$client->share_token}", "/c/{$client->share_token}/machines/{$machine->id}"] as $path) {
            $this->get($path)
                ->assertOk()
                ->assertDontSee('Samsung')
                ->assertDontSee('Windows health and security')
                ->assertInertia(function ($page): void {
                    $props = (string) json_encode($page->toArray()['props']);

                    expect($page->toArray()['props'])->not->toHaveKey('detailCards')
                        ->and($props)->not->toContain('Samsung')->not->toContain('Backup disk')->not->toContain('Antivirus')->not->toContain('User Account Control')
                        ->not->toContain('worn')->not->toContain('Windows health');
                });
        }
    });
});

describe('what needs attention', function () {
    it('stays quiet for healthy machines', function (string $fixture, string $device) {
        $user = User::factory()->create();
        wcMachine($user, wcFixture($fixture), [], $device);

        expect(wcAttention($user)['total'])->toBe(0);
    })->with([
        'a healthy laptop' => ['laptop-healthy', 'laptop'],
        'a desktop without encryption' => ['desktop-healthy', 'desktop'],
        'a laptop with another antivirus' => ['dutch-laptop', 'laptop'],
    ]);

    it('puts an unhealthy laptop on the list as urgent with its reasons', function () {
        $row = wcRow(wcFixture('laptop-unhealthy'));

        expect($row['level'])->toBe('urgent')
            ->and($row['reason_count'])->toBe(9)
            ->and($row['reasons'])->toBe([
                ['level' => 'urgent', 'text' => 'A drive reports that it is not healthy'],
                ['level' => 'urgent', 'text' => 'A drive is more than 80% worn out'],
            ]);
    });

    it('lists a machine with only a small problem as soon', function () {
        $row = wcRow(str_replace('UAC: on', 'UAC: off', wcFixture('laptop-healthy')));

        expect($row['level'])->toBe('soon')
            ->and($row['reasons'])->toBe([['level' => 'soon', 'text' => 'User Account Control is switched off']]);
    });

    it('does not repeat what the script flags already say, but keeps the flags it does not measure', function () {
        $flags = [
            "Disk 'Samsung SSD 970' health is 'Warning'.",
            'Disk 0 SSD wear is 91% (near end of life).',
            'Defender real-time protection is OFF and no other antivirus was found.',
            'SMBv1 is enabled (an outdated file sharing protocol that ransomware has abused).',
            'Remote Desktop (RDP) is enabled, so people on the network can try to sign in to this PC.',
        ];
        $row = wcRow(wcFixture('laptop-unhealthy'), $flags);

        expect($row['reason_count'])->toBe(10);
    });

    it('keeps a script flag when the health section could not read the same thing', function () {
        $old = (string) file_get_contents(base_path('tests/Fixtures/windows-checkup-laptop.md'));
        $flags = [
            "Disk 'Samsung SSD 970' health is 'Warning'.",
            'Defender real-time protection is OFF and no other antivirus was found.',
            'SMBv1 is enabled (an outdated file sharing protocol that ransomware has abused).',
        ];
        $blind = $old."\n## Windows health and security\n\n```\nDrives: could not check\nDefender real-time protection: could not check\nSMBv1: could not check\n```\n";
        $partial = $old."\n## Windows health and security\n\n```\nDefender real-time protection: on\nSMBv1: could not check\n```\n";

        expect(wcRow($blind, $flags)['reason_count'])->toBe(3)
            ->and(wcRow($partial, $flags)['reason_count'])->toBe(2);
    });

    it('still uses the script flags for a report without the section', function () {
        $old = (string) file_get_contents(base_path('tests/Fixtures/windows-checkup-laptop.md'));
        $row = wcRow($old, ['Defender real-time protection is OFF and no other antivirus was found.']);

        expect(array_column($row['reasons'], 'level'))->toContain('urgent')
            ->and($row['reason_count'])->toBe(1);
    });

    it('never shows another account the machine', function () {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        wcMachine($owner, wcFixture('laptop-unhealthy'));

        expect(wcAttention($owner)['total'])->toBe(1)
            ->and(wcAttention($other)['total'])->toBe(0);
    });
});
