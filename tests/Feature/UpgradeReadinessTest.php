<?php

declare(strict_types=1);

use App\Models\Client;
use App\Models\Machine;
use App\Models\Report;
use App\Models\User;

use function Pest\Laravel\actingAs;

/**
 * @param  list<string>  $flags
 * @return list<array{id: string, title: string, rows: list<array{label: string, value: string, tone: string}>, list: mixed}>
 */
function upgradeOwnerCards(string $markdown, array $flags = [], string $deviceType = 'laptop'): array
{
    $user = User::factory()->create();
    $machine = Machine::factory()->for($user)->create(['device_type' => $deviceType]);
    Report::factory()->for($machine)->create(['markdown' => $markdown, 'flags' => $flags]);

    $cards = [];

    actingAs($user)->get("/machines/{$machine->id}")
        ->assertOk()
        ->assertInertia(function ($page) use (&$cards): void {
            $cards = $page->toArray()['props']['detailCards'];
        });

    return $cards;
}

function readinessFixture(string $name): string
{
    return (string) file_get_contents(__DIR__."/../Fixtures/windows10-readiness-{$name}.md");
}

/**
 * @param  list<array{id: string, title: string, rows: list<array{label: string, value: string, tone: string}>, list: mixed}>  $cards
 * @return array{id: string, title: string, rows: list<array{label: string, value: string, tone: string}>, list: mixed}|null
 */
function cardById(array $cards, string $id): ?array
{
    return collect($cards)->firstWhere('id', $id);
}

describe('Windows 11 readiness card', function () {
    it('gives the owner a yes for a Dutch Windows 10 laptop with the upgrade options row', function () {
        $card = cardById(upgradeOwnerCards(readinessFixture('laptop')), 'upgrade');

        expect($card['title'])->toBe('Can this PC run Windows 11?')
            ->and($card['rows'][0])->toBe(['label' => 'Verdict', 'value' => 'Yes', 'tone' => 'good'])
            ->and(array_column($card['rows'], 'label'))->toBe(['Verdict', 'Upgrade options', 'Good to know'])
            ->and($card['rows'][1]['value'])->toBe('RAM can be expanded: 1 of 2 slots free, up to 32 GB');
    });

    it('tells the owner no for a Windows 10 desktop with an old processor and TPM 1.2', function () {
        $card = cardById(upgradeOwnerCards(readinessFixture('desktop'), [], 'desktop'), 'upgrade');

        expect($card['rows'][0])->toBe(['label' => 'Verdict', 'value' => 'No', 'tone' => 'bad'])
            ->and(array_column($card['rows'], 'label'))->toContain('The processor is too old', 'The security chip (TPM) is too old');
    });

    it('sits before the worth keeping card', function () {
        $ids = array_column(upgradeOwnerCards(readinessFixture('laptop')), 'id');

        expect($ids)->toContain('upgrade');

        if (in_array('verdict', $ids, true)) {
            expect(array_search('upgrade', $ids, true))->toBeLessThan(array_search('verdict', $ids, true));
        }
    });

    it('is not shown for a Windows 11 machine', function () {
        $markdown = str_replace('Windows 10 Pro 22H2 (build 19045.6396)', 'Windows 11 Pro 24H2 (build 26100.1)', readinessFixture('laptop'));

        expect(array_column(upgradeOwnerCards($markdown), 'id'))->not->toContain('upgrade');
    });

    it('is not shown for a Mac or for an old report without the section', function () {
        expect(array_column(upgradeOwnerCards(macReportFixture()), 'id'))->not->toContain('upgrade')
            ->and(array_column(upgradeOwnerCards(windowsLaptopFixture()), 'id'))->not->toContain('upgrade');
    });

    it('never reaches the share pages', function () {
        $client = Client::factory()->withShareLink()->create();
        $machine = Machine::factory()->for($client->user)->create(['client_id' => $client->id, 'device_type' => 'laptop']);
        Report::factory()->for($machine)->create(['markdown' => readinessFixture('desktop'), 'flags' => []]);

        foreach (["/c/{$client->share_token}", "/c/{$client->share_token}/machines/{$machine->id}"] as $path) {
            $this->get($path)
                ->assertOk()
                ->assertInertia(function ($page): void {
                    $props = $page->toArray()['props'];
                    $encoded = (string) json_encode($props);

                    expect($props)->not->toHaveKey('detailCards')
                        ->and($encoded)->not->toContain('Windows 11')
                        ->and($encoded)->not->toContain('PC Health Check')
                        ->and($encoded)->not->toContain('TPM')
                        ->and($encoded)->not->toContain('Upgrade options')
                        ->and($encoded)->not->toContain('Newest macOS');
                });
        }
    });

    it('does not show one account the machine of another', function () {
        $machine = Machine::factory()->for(User::factory()->create())->create();
        Report::factory()->for($machine)->create(['markdown' => readinessFixture('laptop'), 'flags' => []]);

        actingAs(User::factory()->create())->get("/machines/{$machine->id}")->assertForbidden();
    });
});

describe('Newest macOS on the Age and support card', function () {
    function macAgeRows(string $model, string $macos): array
    {
        $markdown = preg_replace(['/^- Model: .*$/m', '/^- macOS: .*$/m'], ["- Model: {$model}", "- macOS: {$macos}"], macReportFixture());

        return cardById(upgradeOwnerCards((string) $markdown), 'age')['rows'];
    }

    it('names the newest macOS and offers the update when the Mac runs an older one', function () {
        $rows = macAgeRows('MacBook Air (MacBookAir9,1)', '10.14.6 (18G9323)');

        expect(collect($rows)->firstWhere('label', 'Newest macOS this Mac can run'))->toBe(['label' => 'Newest macOS this Mac can run', 'value' => 'macOS 15 Sequoia', 'tone' => 'neutral'])
            ->and(collect($rows)->firstWhere('label', 'Can be updated to'))->toBe(['label' => 'Can be updated to', 'value' => 'macOS 15 Sequoia', 'tone' => 'neutral']);
    });

    it('does not offer an update to a macOS that no longer gets security updates', function () {
        $labels = array_column(macAgeRows('MacBook Air (MacBookAir7,2)', '10.14.6 (18G9323)'), 'label');

        expect($labels)->toContain('Newest macOS this Mac can run')->not->toContain('Can be updated to');
    });

    it('does not offer an update when the Mac already runs the newest', function () {
        $rows = macAgeRows('MacBook Air (MacBookAir7,2)', '12.7.6 (21H1320)');
        $labels = array_column($rows, 'label');

        expect($labels)->toContain('Newest macOS this Mac can run')->not->toContain('Can be updated to');
    });

    it('says nothing when the Mac runs something newer than the table allows', function () {
        $labels = array_column(macAgeRows('MacBook Air (MacBookAir7,2)', '14.5 (23F79)'), 'label');

        expect($labels)->not->toContain('Newest macOS this Mac can run')->not->toContain('Can be updated to');
    });

    it('shows the newest macOS without an update row when the running version is unreadable', function () {
        $labels = array_column(macAgeRows('MacBook Air (MacBookAir7,2)', 'unknown'), 'label');

        expect($labels)->toContain('Newest macOS this Mac can run')->not->toContain('Can be updated to');
    });

    it('says nothing for an identifier it does not know', function () {
        $labels = array_column(macAgeRows('Mac (MacBookAir99,9)', '10.14.6 (18G9323)'), 'label');

        expect($labels)->not->toContain('Newest macOS this Mac can run')->not->toContain('Can be updated to');
    });

    it('treats a very old running macOS like any older one', function () {
        $labels = array_column(macAgeRows('MacBook Air (MacBookAir9,1)', '9.1'), 'label');

        expect($labels)->toContain('Newest macOS this Mac can run')->toContain('Can be updated to');
    });

    it('never shows the rows to a client on the share pages', function () {
        $client = Client::factory()->withShareLink()->create();
        $machine = Machine::factory()->for($client->user)->create(['client_id' => $client->id]);
        Report::factory()->for($machine)->create(['markdown' => macReportFixture(), 'flags' => []]);

        foreach (["/c/{$client->share_token}", "/c/{$client->share_token}/machines/{$machine->id}"] as $path) {
            $this->get($path)->assertOk()->assertInertia(fn ($page) => expect((string) json_encode($page->toArray()['props']))->not->toContain('Newest macOS')->not->toContain('Can be updated to'));
        }
    });
});
