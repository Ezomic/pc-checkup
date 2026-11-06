<?php

declare(strict_types=1);

use App\Enums\ChecklistOutcome;
use App\Models\ChecklistEntry;
use App\Models\Client;
use App\Models\Machine;
use App\Models\MachineNote;
use App\Models\Photo;
use App\Models\Report;
use App\Models\ScanCode;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseCount;
use function Pest\Laravel\assertDatabaseHas;

beforeEach(function () {
    Storage::fake('photos');
});

function photoPath(Machine $machine): string
{
    return "m/{$machine->id}.jpg";
}

function fullMachine(User $user, ?Client $client = null): Machine
{
    $machine = Machine::factory()->for($user)->create(['client_id' => $client?->id]);
    $before = Report::factory()->for($machine)->create();
    Report::factory()->for($machine)->create(['before_report_id' => $before->id]);
    Report::factory()->for($machine)->create()->delete();
    ChecklistEntry::factory()->for($machine)->create(['item_key' => 'screen', 'outcome' => ChecklistOutcome::Pass]);
    MachineNote::factory()->for($machine)->create();
    Storage::disk('photos')->put(photoPath($machine), 'x');
    Photo::factory()->for($machine)->create(['path' => photoPath($machine)]);

    return $machine;
}

describe('DELETE /machines/{machine}', function () {
    it('deletes the machine and every row that belongs to it, trashed reports included', function () {
        $user = User::factory()->create();
        $machine = fullMachine($user);
        $other = fullMachine($user);

        actingAs($user)->delete("/machines/{$machine->id}")
            ->assertRedirect('/machines')
            ->assertSessionHas('inertia.flash_data.toast.type', 'success');

        expect(Machine::withTrashed()->find($machine->id))->toBeNull()
            ->and(Report::withTrashed()->where('machine_id', $machine->id)->count())->toBe(0)
            ->and(ChecklistEntry::query()->where('machine_id', $machine->id)->count())->toBe(0)
            ->and(MachineNote::query()->where('machine_id', $machine->id)->count())->toBe(0)
            ->and(Photo::query()->where('machine_id', $machine->id)->count())->toBe(0);
        Storage::disk('photos')->assertMissing(photoPath($machine));
        Storage::disk('photos')->assertExists(photoPath($other));

        expect(Machine::query()->find($other->id))->not->toBeNull()
            ->and(Report::withTrashed()->where('machine_id', $other->id)->count())->toBe(3)
            ->and(Photo::query()->where('machine_id', $other->id)->count())->toBe(1);
    });

    it('leaves nothing behind in any table', function () {
        $user = User::factory()->create();
        $machine = fullMachine($user);

        actingAs($user)->delete("/machines/{$machine->id}");

        foreach (['machines', 'reports', 'checklist_entries', 'machine_notes', 'photos'] as $table) {
            assertDatabaseCount($table, 0);
        }
    });

    it('detaches a scan code that pointed at a deleted report instead of failing', function () {
        $user = User::factory()->create();
        $machine = fullMachine($user);
        $report = $machine->reports()->firstOrFail();
        $code = ScanCode::factory()->for($user)->used($report)->create();

        actingAs($user)->delete("/machines/{$machine->id}")->assertRedirect('/machines');

        expect($code->fresh()?->report_id)->toBeNull();
    });

    it('deletes a machine that was soft deleted earlier', function () {
        $user = User::factory()->create();
        $machine = fullMachine($user);
        $machine->delete();

        actingAs($user)->delete("/machines/{$machine->id}")->assertRedirect('/machines');

        expect(Machine::withTrashed()->find($machine->id))->toBeNull()
            ->and(Report::withTrashed()->count())->toBe(0);
    });

    it('stops the client share link from showing the machine', function () {
        $user = User::factory()->create();
        $client = Client::factory()->for($user)->withShareLink()->create();
        $machine = fullMachine($user, $client);
        $path = "/c/{$client->share_token}/machines/{$machine->id}";

        $this->get($path)->assertOk();

        actingAs($user)->delete("/machines/{$machine->id}");

        $this->get($path)->assertNotFound();
        $this->get("/c/{$client->share_token}/machines/{$machine->id}/before-after")->assertNotFound();
        $this->get("/c/{$client->share_token}")->assertOk()->assertInertia(fn ($page) => $page->has('machines', 0));
        assertDatabaseHas('clients', ['id' => $client->id]);
    });

    it('forbids another userâ€™s machine and deletes nothing', function () {
        $machine = fullMachine(User::factory()->create());

        actingAs(User::factory()->create())->delete("/machines/{$machine->id}")->assertForbidden();

        expect(Machine::query()->find($machine->id))->not->toBeNull()
            ->and(Report::withTrashed()->where('machine_id', $machine->id)->count())->toBe(3);
        Storage::disk('photos')->assertExists(photoPath($machine));
    });

    it('forbids another userâ€™s soft deleted machine too', function () {
        $machine = fullMachine(User::factory()->create());
        $machine->delete();

        actingAs(User::factory()->create())->delete("/machines/{$machine->id}")->assertForbidden();

        expect(Machine::withTrashed()->find($machine->id))->not->toBeNull();
    });

    it('answers 404 for a machine that does not exist', function () {
        actingAs(User::factory()->create())->delete('/machines/999999')->assertNotFound();
    });

    it('requires authentication', function () {
        $machine = fullMachine(User::factory()->create());

        $this->delete("/machines/{$machine->id}")->assertRedirect('/login');

        assertDatabaseHas('machines', ['id' => $machine->id]);
    });

    it('refuses a request without a CSRF token', function () {
        $user = User::factory()->create();
        $machine = fullMachine($user);

        app()->instance('env', 'production');

        actingAs($user)->delete("/machines/{$machine->id}")->assertStatus(419);

        assertDatabaseHas('machines', ['id' => $machine->id]);
    });
});
