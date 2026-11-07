<?php

declare(strict_types=1);

use App\Enums\ShareLocale;
use App\Models\Client;
use App\Models\Machine;
use App\Models\Report;
use App\Models\User;
use App\Services\NeedsAttention;

use function Pest\Laravel\actingAs;

const BROKEN_CUT = 'The report stops early: its last part is missing, so the scan or the upload was probably cut off.';

function brokenCutReport(): string
{
    return "# Windows checkup: Box\n\nThis report comes from one scan.\n\n- Format: 1\n- Script version: 00000000\n- OS: Windows\n- Serial: BRK123\n- Free disk: 40%\n\n"
        ."## Things to look at\n\n- Nothing flagged automatically.\n\n## Hardware overview\n\n```\nModel : OptiPlex 7050\n```\n";
}

function brokenMachine(User $user, string $markdown, ?Client $client = null): Machine
{
    $machine = Machine::factory()->for($user)->create(['name' => 'Front-desk', 'os' => 'Windows', 'client_id' => $client?->id]);
    Report::factory()->for($machine)->create(['markdown' => $markdown, 'checksum' => hash('sha256', $markdown), 'created_at' => now()->subDay()]);

    return $machine;
}

function brokenGoodReport(): string
{
    return (string) file_get_contents(base_path('tests/Fixtures/windows-checkup-old-hosts.md'));
}

/**
 * @return list<array{level: string, text: string}>
 */
function brokenReasons(User $user): array
{
    $reasons = [];

    actingAs($user)->get('/machines')->assertOk()->assertInertia(function ($page) use (&$reasons): void {
        $reasons = array_merge(...array_column($page->toArray()['props']['needsAttention']['rows'], 'reasons'));
    });

    return $reasons;
}

describe('the owner machine page', function () {
    it('lists what looks wrong with the latest report', function () {
        $user = User::factory()->create();
        $machine = brokenMachine($user, brokenCutReport());

        actingAs($user)->get("/machines/{$machine->id}")->assertOk()->assertInertia(fn ($page) => $page
            ->component('machines/Show')
            ->where('report.problems', [BROKEN_CUT]));
    });

    it('lists nothing for a complete report', function () {
        $user = User::factory()->create();
        $machine = brokenMachine($user, brokenGoodReport());

        actingAs($user)->get("/machines/{$machine->id}")->assertOk()->assertInertia(fn ($page) => $page
            ->where('report.problems', [])
            ->has('detailCards'));
    });

    it('only judges the latest report', function () {
        $user = User::factory()->create();
        $machine = brokenMachine($user, brokenCutReport());
        Report::factory()->for($machine)->create(['markdown' => brokenGoodReport(), 'checksum' => 'newer', 'created_at' => now()]);

        actingAs($user)->get("/machines/{$machine->id}")->assertInertia(fn ($page) => $page->where('report.problems', []));
    });

    it('is not shown to someone else, who cannot open the machine at all', function () {
        $machine = brokenMachine(User::factory()->create(), brokenCutReport());

        actingAs(User::factory()->create())->get("/machines/{$machine->id}")->assertForbidden();
    });
});

describe('needs attention', function () {
    it('gives a broken report as a reason to look', function () {
        $user = User::factory()->create();
        brokenMachine($user, brokenCutReport());

        expect(brokenReasons($user))->toBe([['level' => 'soon', 'text' => NeedsAttention::BROKEN_REPORT]]);
    });

    it('says nothing about a complete report', function () {
        $user = User::factory()->create();
        brokenMachine($user, brokenGoodReport());

        expect(array_column(brokenReasons($user), 'text'))->not->toContain(NeedsAttention::BROKEN_REPORT);
    });

    it('never lists the broken report of another account', function () {
        brokenMachine(User::factory()->create(), brokenCutReport());

        expect(brokenReasons(User::factory()->create()))->toBe([]);
    });

    it('gives the reason on the owner client page too', function () {
        $user = User::factory()->create();
        $client = Client::factory()->for($user)->create();
        brokenMachine($user, brokenCutReport(), $client);

        actingAs($user)->get("/clients/{$client->id}")->assertOk()->assertInertia(fn ($page) => $page
            ->where('machines.0.reasons', [['level' => 'soon', 'text' => NeedsAttention::BROKEN_REPORT]]));
    });
});

describe('the share pages', function () {
    it('never say a report looks broken', function () {
        $user = User::factory()->create();
        $client = Client::factory()->for($user)->withShareLink()->create(['locale' => ShareLocale::English]);
        $machine = brokenMachine($user, brokenCutReport(), $client);

        $this->get("/c/{$client->share_token}")->assertOk()
            ->assertDontSee('looks broken')->assertDontSee('stops early');

        $this->get("/c/{$client->share_token}/machines/{$machine->id}")->assertOk()
            ->assertDontSee('looks broken')->assertDontSee('stops early')
            ->assertInertia(fn ($page) => $page
                ->component('share/Machine')
                ->has('report')
                ->missing('report.problems'));
    });
});
