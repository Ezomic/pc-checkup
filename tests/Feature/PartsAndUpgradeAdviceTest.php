<?php

declare(strict_types=1);

use App\Actions\Machines\BuildUpgradeAdvice;
use App\Models\Client;
use App\Models\Machine;
use App\Models\PriceItem;
use App\Models\Report;
use App\Models\User;
use Carbon\CarbonImmutable;

use function Pest\Laravel\actingAs;

beforeEach(fn () => CarbonImmutable::setTestNow('2026-11-07 12:00:00'));
afterEach(fn () => CarbonImmutable::setTestNow());

function puaMarkdown(string $memory = '4 GB'): string
{
    return "# Windows checkup: PARTS-PC\n\n- Format: 1\n- OS: Windows\n- Device type: laptop\n- Serial: PUASERIAL01\n- Model: Contoso Voyager 14\n- Memory: {$memory}\n- Free disk: 40%\n"
        ."\n## Storage (physical disks)\n\n```\nFriendlyName MediaType BusType SizeGB HealthStatus OperationalStatus\n------------ --------- ------- ------ ------------ -----------------\nST500LM000 HDD SATA 466 Healthy OK\n```\n"
        ."\n## BIOS and firmware age\n\n```\nBIOS release date: 2020-03-01 (about 6 full years ago)\n```\n"
        ."\n## Windows 11 readiness\n\n```\nMemory: {$memory}\nMemory slots: 2 total, 1 used\nMemory form: socketed\nMemory maximum: 16 GB\n```\n"
        ."\n## Memory modules\n\n```\nformat: 1\nscan: complete\nmodule|ChannelA-DIMM0|4096 MB|DDR4|2400 MHz|SODIMM\n```\n";
}

/**
 * @return array<string, mixed>
 */
function puaProps(User $user, Machine $machine): array
{
    $props = [];

    actingAs($user)->get("/machines/{$machine->id}")->assertOk()->assertInertia(function ($page) use (&$props): void {
        $props = $page->toArray()['props'];
    });

    return $props;
}

describe('the owner page', function () {
    it('gives the owner the parts card in the camelCase detailCards prop', function () {
        $user = User::factory()->create();
        $machine = Machine::factory()->for($user)->create(['device_type' => 'laptop']);
        Report::factory()->for($machine)->create(['markdown' => puaMarkdown()]);

        $card = collect(puaProps($user, $machine)['detailCards'])->firstWhere('id', 'parts');

        expect($card['title'] ?? null)->toBe('Parts')
            ->and(array_column($card['rows'] ?? [], 'value', 'label'))->toMatchArray([
                'Memory' => '4 GB in 1 of 2 slots',
                'Memory type' => 'DDR4 SODIMM, 2400 MHz',
                'Upgradeable' => 'Yes, 1 free slot',
                'Most memory supported' => '16 GB',
                'ChannelA-DIMM0' => '4 GB',
                'Drive 1' => 'SATA hard drive (HDD), 466 GB',
            ]);
    });

    it('gives one upgrade advice with the price of the matching item in the owner\'s own price list', function () {
        $user = User::factory()->create();
        $machine = Machine::factory()->for($user)->create(['device_type' => 'laptop']);
        Report::factory()->for($machine)->create(['markdown' => puaMarkdown()]);
        PriceItem::factory()->for($user)->create(['name' => 'Werkuur', 'price_cents' => 6000, 'sort_order' => 0]);
        PriceItem::factory()->for($user)->create(['name' => 'SSD 500 GB 2,5 inch', 'price_cents' => 6900, 'unit' => 'stuk', 'sort_order' => 1]);
        PriceItem::factory()->create(['name' => 'SSD 120 GB cheapest', 'price_cents' => 100, 'sort_order' => 0]);

        expect(puaProps($user, $machine)['upgradeAdvice'])->toBe([
            'text' => 'Swap the 466 GB spinning hard drive for a SATA SSD: the biggest speed gain for a machine of about 6 years old.',
            'price' => ['name' => 'SSD 500 GB 2,5 inch', 'price_cents' => 6900, 'unit' => 'stuk'],
        ]);
    });

    it('never takes a price from another account, even when only theirs matches', function () {
        $user = User::factory()->create();
        $machine = Machine::factory()->for($user)->create();
        Report::factory()->for($machine)->create(['markdown' => puaMarkdown()]);
        PriceItem::factory()->for($user)->create(['name' => 'Werkuur']);
        PriceItem::factory()->create(['name' => 'SSD from another account', 'price_cents' => 4242]);

        $props = puaProps($user, $machine);

        expect($props['upgradeAdvice'])->toHaveKey('price')->toHaveKey('text')
            ->and($props['upgradeAdvice']['price'])->toBeNull()
            ->and(json_encode($props))->not->toContain('another account')->not->toContain('4242');
    });

    it('gives the memory advice with the price of the same memory generation once the drive is solid state', function () {
        $user = User::factory()->create();
        $machine = Machine::factory()->for($user)->create();
        Report::factory()->for($machine)->create(['markdown' => str_replace('ST500LM000 HDD SATA 466', 'Fast SSD NVMe 477', puaMarkdown())]);
        PriceItem::factory()->for($user)->create(['name' => 'RAM 4 GB DDR3', 'price_cents' => 1500, 'sort_order' => 0]);
        PriceItem::factory()->for($user)->create(['name' => 'RAM 4 GB DDR4', 'price_cents' => 2500, 'sort_order' => 1]);

        expect(puaProps($user, $machine)['upgradeAdvice'])->toBe([
            'text' => 'Add a module of 4 GB (DDR4 SODIMM, 2400 MHz) in the free slot to go from 4 to 8 GB of memory.',
            'price' => ['name' => 'RAM 4 GB DDR4', 'price_cents' => 2500, 'unit' => null],
        ]);
    });

    it('gives no advice when the machine is too old, nothing can be upgraded or there is no report', function (?string $markdown) {
        $user = User::factory()->create();
        $machine = Machine::factory()->for($user)->create();

        if ($markdown !== null) {
            Report::factory()->for($machine)->create(['markdown' => $markdown]);
        }

        expect(puaProps($user, $machine)['upgradeAdvice'])->toBeNull();
    })->with([
        'too old' => [str_replace('2020-03-01', '2014-03-01', puaMarkdown())],
        'nothing to upgrade' => [str_replace(['ST500LM000 HDD SATA 466', '4096 MB'], ['Fast SSD NVMe 477', '8192 MB'], puaMarkdown('8 GB'))],
        'no report' => [null],
    ]);

    it('gives no advice when the verdict card of the page says replace', function () {
        $user = User::factory()->create();
        $machine = Machine::factory()->for($user)->create();
        $report = Report::factory()->for($machine)->create(['markdown' => puaMarkdown()]);
        PriceItem::factory()->for($user)->create(['name' => 'SSD 500 GB']);
        $verdict = ['id' => 'verdict', 'title' => 'Worth keeping?', 'rows' => [['label' => 'Verdict', 'value' => 'Replace', 'tone' => 'bad']], 'list' => null];

        $items = array_values($user->priceItems()->get()->all());

        expect(app(BuildUpgradeAdvice::class)->handle($report, [$verdict], $items))->toBeNull()
            ->and(app(BuildUpgradeAdvice::class)->handle($report, [], $items))->not->toBeNull();
    });

    it('does not show one account the parts or the advice of another', function () {
        $theirs = Machine::factory()->for(User::factory()->create())->create();
        Report::factory()->for($theirs)->create(['markdown' => puaMarkdown()]);

        actingAs(User::factory()->create())->get("/machines/{$theirs->id}")->assertForbidden();
    });
});

describe('the client share pages', function () {
    it('never get the parts card, the advice or the price', function (bool $machinePage) {
        $client = Client::factory()->withShareLink()->create();
        $machine = Machine::factory()->for($client->user)->create(['client_id' => $client->id]);
        Report::factory()->for($machine)->create(['markdown' => puaMarkdown()]);
        PriceItem::factory()->for($client->user)->create(['name' => 'SSD secret price', 'price_cents' => 777700]);

        $response = $this->get("/c/{$client->share_token}".($machinePage ? "/machines/{$machine->id}" : ''))->assertOk();
        $props = '';
        $response->assertInertia(function ($page) use (&$props): void {
            $props = (string) json_encode($page->toArray()['props']);
        });

        foreach ([$props, (string) $response->getContent()] as $body) {
            foreach (['upgradeAdvice', 'SSD secret price', '7777', 'ChannelA-DIMM0', 'SODIMM', 'Most memory supported', 'spinning hard drive for', '"parts"', 'detailCards'] as $hidden) {
                expect($body)->not->toContain($hidden);
            }
        }
    })->with([[false], [true]]);
});
