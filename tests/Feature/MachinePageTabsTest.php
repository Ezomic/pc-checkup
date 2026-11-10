<?php

declare(strict_types=1);

use App\Models\Client;
use App\Models\Machine;
use App\Models\Report;
use App\Models\User;
use App\Services\Reports\DetailCardTabs;

use function Pest\Laravel\actingAs;

const MPT_MOJAVE_FLAGS = [
    'macOS 10.14.6 is old and likely out of security support.',
    'FileVault is OFF.',
    'Application firewall is off.',
    'Only about 11% of disk is free.',
    'No macOS update has been installed for 1748 days (newest: 2022-01-26).',
];

function mojaveFixture(): string
{
    return (string) file_get_contents(__DIR__.'/../Fixtures/mac-checkup-mojave-full.md');
}

/**
 * @param  list<string>  $flags
 * @return array{0: User, 1: Machine}
 */
function mptMachine(?string $markdown, array $flags = []): array
{
    $user = User::factory()->create();
    $machine = Machine::factory()->for($user)->create(['device_type' => 'laptop']);

    if ($markdown !== null) {
        Report::factory()->for($machine)->create(['markdown' => $markdown, 'flags' => $flags]);
    }

    return [$user, $machine];
}

/**
 * @param  list<string>  $flags
 * @return array{detailCards: list<array<string, mixed>>, verdictCard: array<string, mixed>|null}
 */
function mptProps(?string $markdown, array $flags = []): array
{
    [$user, $machine] = mptMachine($markdown, $flags);
    $props = [];

    actingAs($user)->get("/machines/{$machine->id}")
        ->assertOk()
        ->assertInertia(function ($page) use (&$props): void {
            $props = $page->toArray()['props'];
        });

    return ['detailCards' => $props['detailCards'], 'verdictCard' => $props['verdictCard']];
}

describe('a Mac shaped like the old MacBook Air at 11 percent free', function () {
    it('flags storage, security and updates, and calls the network settings fine', function () {
        $statuses = array_column(mptProps(mojaveFixture(), MPT_MOJAVE_FLAGS)['detailCards'], 'status', 'id');

        expect($statuses['storage'])->toBe('attention')
            ->and($statuses['security'])->toBe('attention')
            ->and($statuses['updates'])->toBe('attention')
            ->and($statuses['network-settings'])->toBe('fine')
            ->and($statuses['age'])->toBe('attention')
            ->and($statuses['storage-breakdown'])->toBe('neutral')
            ->and($statuses['remote-access'])->toBe('neutral');
    });

    it('sends the cards in tab order with a group each, and the verdict on its own', function () {
        $props = mptProps(mojaveFixture(), MPT_MOJAVE_FLAGS);
        $groups = array_values(array_unique(array_column($props['detailCards'], 'group')));

        expect($groups)->toBe(['Security and backup', 'Speed and stability', 'Storage', 'Hardware'])
            ->and(array_column($props['detailCards'], 'id'))->not->toContain('verdict')
            ->and($props['verdictCard']['title'])->toBe('Worth keeping?')
            ->and($props['verdictCard']['rows'][0]['label'])->toBe('Verdict')
            ->and($props['verdictCard'])->not->toHaveKeys(['group', 'status']);
    });

    it('keeps storage attention from the flag alone when the free space row is plain', function () {
        $storage = collect(mptProps(mojaveFixture(), MPT_MOJAVE_FLAGS)['detailCards'])->firstWhere('id', 'storage');
        $withoutFlag = collect(mptProps(mojaveFixture(), [])['detailCards'])->firstWhere('id', 'storage');

        expect(array_column($storage['rows'], 'tone'))->not->toContain('bad')
            ->and($storage['status'])->toBe('attention')
            ->and($withoutFlag['status'])->toBe('fine');
    });
});

it('groups a Windows report, with the age card needing attention for the unsupported Windows 10', function () {
    $props = mptProps(windowsLaptopFixture(), ['Windows 10 Pro reached end of support on 14 October 2025, so it only gets updates with a paid extension.']);
    $statuses = array_column($props['detailCards'], 'status', 'id');

    expect($statuses['age'])->toBe('attention')
        ->and($statuses['storage'])->toBe('fine')
        ->and(array_diff(array_column($props['detailCards'], 'group'), [...array_keys(DetailCardTabs::GROUPS), DetailCardTabs::OTHER]))->toBe([])
        ->and($props['verdictCard'])->not->toBeNull();
});

it('groups an older Mac report that has fewer sections', function () {
    $props = mptProps(macReportFixture(), ['Only about 12% of disk is free.', 'macOS 10.14.6 is old and likely out of security support.']);

    expect(array_column($props['detailCards'], 'id'))->toBe(['security', 'backups', 'updates', 'slowness', 'startup', 'boot-time', 'stability', 'storage', 'reclaimable', 'unused-apps', 'age', 'battery'])
        ->and(array_column($props['detailCards'], 'status', 'id')['storage'])->toBe('attention');
});

it('sends no cards and no verdict for a machine without a report', function () {
    expect(mptProps(null))->toBe(['detailCards' => [], 'verdictCard' => null]);
});

it('never shows the cards of another account', function () {
    [, $machine] = mptMachine(mojaveFixture(), MPT_MOJAVE_FLAGS);

    actingAs(User::factory()->create())->get("/machines/{$machine->id}")->assertForbidden();
});

it('never puts the verdict, groups or statuses on the client share page', function () {
    $client = Client::factory()->withShareLink()->create();
    $machine = Machine::factory()->for($client->user)->create(['client_id' => $client->id, 'device_type' => 'laptop']);
    Report::factory()->for($machine)->create(['markdown' => mojaveFixture(), 'flags' => MPT_MOJAVE_FLAGS]);

    foreach (["/c/{$client->share_token}", "/c/{$client->share_token}/machines/{$machine->id}"] as $path) {
        $response = $this->get($path)->assertOk();
        $props = '';
        $response->assertInertia(function ($page) use (&$props): void {
            $props = (string) json_encode($page->toArray()['props']);
        });

        foreach ([$props, (string) $response->getContent()] as $body) {
            foreach (['verdictCard', 'Worth keeping?', 'Security and backup', 'Speed and stability', '"status":"attention"', 'detailCards'] as $hidden) {
                expect($body)->not->toContain($hidden);
            }
        }
    }
});
