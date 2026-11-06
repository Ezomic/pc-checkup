<?php

declare(strict_types=1);

use App\Models\Client;
use App\Models\Machine;
use App\Models\Report;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;

const OVERVIEW_OLD_OS = 'macOS 10.14.6 is old and likely out of security support.';

beforeEach(fn () => CarbonImmutable::setTestNow('2026-11-06 12:00:00'));
afterEach(fn () => CarbonImmutable::setTestNow());

/**
 * @param  array<string, string>  $header
 */
function overviewMarkdown(array $header = [], bool $spinning = false): string
{
    $lines = ['# Checkup', '', '- Format: 1'];

    foreach (['Model' => 'MacBook Pro (MacBookPro18,1)', 'macOS' => '14.5 (23F79)', 'Memory' => '16 GB', 'Free disk' => '50%', ...$header] as $key => $value) {
        $lines[] = "- {$key}: {$value}";
    }

    return implode("\n", $lines)."\n\n## Storage (boot volume)\n\n```\n   Solid State:               ".($spinning ? 'No' : 'Yes')."\n```\n";
}

/**
 * @param  list<string>  $flags
 */
function overviewMachine(Client $client, string $name, ?string $markdown = null, array $flags = [], ?CarbonImmutable $scanned = null): Machine
{
    $machine = Machine::factory()->for($client->user)->create([
        'name' => $name,
        'client_id' => $client->id,
        'created_at' => CarbonImmutable::now()->subYear(),
    ]);

    if ($markdown !== null) {
        Report::factory()->for($machine)->create([
            'markdown' => $markdown,
            'flags' => $flags,
            'created_at' => $scanned ?? CarbonImmutable::now()->subDay(),
        ]);
    }

    return $machine;
}

/**
 * @return array<string, mixed>
 */
function overviewProps(User $user, Client $client): array
{
    $page = actingAs($user)->get("/clients/{$client->id}")->assertOk()->viewData('page');

    return $page['props'];
}

describe('client overview', function () {
    it('lists the machines worst first with level, reasons, verdict and last scan', function () {
        $user = User::factory()->create();
        $client = Client::factory()->for($user)->create();
        $fine = overviewMachine($client, 'Fine one', overviewMarkdown());
        $soon = overviewMachine($client, 'Soon one', overviewMarkdown(['Free disk' => '8%']));
        $urgent = overviewMachine($client, 'Urgent one', overviewMarkdown(['Memory' => '2 GB', 'Free disk' => '3%'], spinning: true), [OVERVIEW_OLD_OS]);

        $props = overviewProps($user, $client);

        expect(array_column($props['machines'], 'id'))->toBe([$urgent->id, $soon->id, $fine->id])
            ->and($props['summary'])->toBe(['total' => 3, 'urgent' => 1, 'soon' => 1, 'fine' => 1])
            ->and($props['machines'][0]['level'])->toBe('urgent')
            ->and($props['machines'][0]['verdict'])->toBe('Repair')
            ->and($props['machines'][0]['reasons'])->toHaveCount(2)
            ->and($props['machines'][0]['reason_count'])->toBeGreaterThan(2)
            ->and($props['machines'][0]['last_scan_at'])->toBe(CarbonImmutable::now()->subDay()->toJSON())
            ->and($props['machines'][1]['reasons'][0])->toBe(['level' => 'soon', 'text' => 'Only 8% of the drive is free'])
            ->and($props['machines'][2])->toMatchArray(['level' => 'fine', 'reasons' => [], 'reason_count' => 0, 'verdict' => 'Keep']);
    });

    it('breaks a tie on the number of reasons, then the name', function () {
        $user = User::factory()->create();
        $client = Client::factory()->for($user)->create();
        $zed = overviewMachine($client, 'Zed', overviewMarkdown(['Free disk' => '8%']));
        $alpha = overviewMachine($client, 'alpha', overviewMarkdown(['Free disk' => '8%']));
        $many = overviewMachine($client, 'Many', overviewMarkdown(['Free disk' => '8%', 'Memory' => '6 GB']));

        expect(array_column(overviewProps($user, $client)['machines'], 'id'))->toBe([$many->id, $alpha->id, $zed->id]);
    });

    it('handles a client without machines', function () {
        $user = User::factory()->create();
        $client = Client::factory()->for($user)->create();

        $props = overviewProps($user, $client);

        expect($props['machines'])->toBe([])
            ->and($props['summary'])->toBe(['total' => 0, 'urgent' => 0, 'soon' => 0, 'fine' => 0]);
    });

    it('treats a machine without a scan as fine when new and soon when it has waited', function () {
        $user = User::factory()->create();
        $client = Client::factory()->for($user)->create();
        $old = overviewMachine($client, 'Old');
        $new = overviewMachine($client, 'New');
        $new->forceFill(['created_at' => CarbonImmutable::now()->subDay()])->save();

        $props = overviewProps($user, $client);

        expect($props['machines'][0])->toMatchArray(['id' => $old->id, 'level' => 'soon', 'last_scan_at' => null, 'verdict' => null])
            ->and($props['machines'][1])->toMatchArray(['id' => $new->id, 'level' => 'fine', 'last_scan_at' => null]);
    });

    it('uses the newest scan that is not deleted', function () {
        $user = User::factory()->create();
        $client = Client::factory()->for($user)->create();
        $machine = overviewMachine($client, 'Box', overviewMarkdown());
        Report::factory()->for($machine)->create([
            'markdown' => overviewMarkdown(['Free disk' => '2%']),
            'flags' => [OVERVIEW_OLD_OS],
            'created_at' => CarbonImmutable::now()->subHour(),
        ])->delete();

        expect(overviewProps($user, $client)['machines'][0])->toMatchArray(['level' => 'fine', 'verdict' => 'Keep']);
    });

    it('ignores a machine of the same user that belongs to another client or to none', function () {
        $user = User::factory()->create();
        $client = Client::factory()->for($user)->create();
        overviewMachine($client, 'Mine', overviewMarkdown());
        $other = Client::factory()->for($user)->create();
        overviewMachine($other, 'Theirs', overviewMarkdown(['Free disk' => '2%']));
        Machine::factory()->for($user)->create();

        expect(overviewProps($user, $client)['summary'])->toBe(['total' => 1, 'urgent' => 0, 'soon' => 0, 'fine' => 1]);
    });

    it('does not show another user’s client', function () {
        $client = Client::factory()->create();

        actingAs(User::factory()->create())->get("/clients/{$client->id}")->assertForbidden();
    });

    it('runs the same number of queries for two machines as for twelve', function () {
        $user = User::factory()->create();
        $client = Client::factory()->for($user)->create();
        overviewMachine($client, 'A', overviewMarkdown(['Free disk' => '8%']));
        overviewMachine($client, 'B', overviewMarkdown());
        actingAs($user);

        DB::enableQueryLog();
        $this->get("/clients/{$client->id}")->assertOk();
        $few = count(DB::getQueryLog());

        foreach (range(1, 10) as $number) {
            overviewMachine($client, "M{$number}", overviewMarkdown(['Free disk' => '8%']));
        }

        DB::flushQueryLog();
        $this->get("/clients/{$client->id}")->assertOk();

        expect(count(DB::getQueryLog()))->toBe($few);
    });

    it('is not reachable through a share link', function () {
        $user = User::factory()->create();
        $client = Client::factory()->for($user)->create(['share_token' => str_repeat('a', 40)]);
        $machine = overviewMachine($client, 'Box', overviewMarkdown());

        $this->get("/c/{$client->share_token}")->assertOk()->assertDontSee('"verdict"', false)->assertDontSee('/history', false);
        $this->get("/c/{$client->share_token}/machines/{$machine->id}/history")->assertNotFound();
    });
});
