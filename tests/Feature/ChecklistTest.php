<?php

declare(strict_types=1);

use App\Enums\ChecklistOutcome;
use App\Models\ChecklistEntry;
use App\Models\Machine;
use App\Models\User;
use App\Services\Checklist\ChecklistResolver;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseCount;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;

/**
 * @return list<string>
 */
function checklistGroups(Machine $machine): array
{
    return array_values(array_unique(array_column((new ChecklistResolver)->for($machine), 'group')));
}

describe('ChecklistResolver', function () {
    it('combines common, device type and os items in that order', function () {
        $machine = Machine::factory()->make(['os' => 'macOS', 'device_type' => 'laptop']);

        expect(checklistGroups($machine))->toBe(['common', 'laptop', 'macos']);
    });

    it('resolves a windows desktop', function () {
        $machine = Machine::factory()->make(['os' => 'Windows 11 Pro', 'device_type' => 'desktop']);

        expect(checklistGroups($machine))->toBe(['common', 'desktop', 'windows']);
    });

    it('ignores case in os and device type', function () {
        $machine = Machine::factory()->make(['os' => 'MACOS', 'device_type' => 'Laptop']);

        expect(checklistGroups($machine))->toBe(['common', 'laptop', 'macos']);
    });

    it('falls back to the common items for an unknown machine', function () {
        $machine = Machine::factory()->make(['os' => null, 'device_type' => null]);

        expect(checklistGroups($machine))->toBe(['common']);
    });

    it('falls back to the common items for an os it does not know', function () {
        $machine = Machine::factory()->make(['os' => 'Plan 9', 'device_type' => 'toaster']);

        expect(checklistGroups($machine))->toBe(['common']);
    });

    it('gives every item a unique key and a label', function () {
        foreach (['macOS', 'Windows'] as $os) {
            foreach (['laptop', 'desktop'] as $type) {
                $items = (new ChecklistResolver)->for(Machine::factory()->make(['os' => $os, 'device_type' => $type]));
                $keys = array_column($items, 'key');

                expect($keys)->toBe(array_values(array_unique($keys)))
                    ->and(array_filter(array_column($items, 'label'), fn (string $l): bool => $l === ''))->toBe([]);
            }
        }
    });
});

describe('checklist on the machine page', function () {
    it('lists the items with no result yet', function () {
        $user = User::factory()->create();
        $machine = Machine::factory()->for($user)->create(['os' => 'macOS', 'device_type' => 'laptop']);

        actingAs($user)->get("/machines/{$machine->id}")
            ->assertInertia(fn ($page) => $page
                ->has('checklist', count((new ChecklistResolver)->for($machine)))
                ->where('checklist.0.outcome', null)
                ->where('checklist.0.note', null)
                ->has('checklist.0.key')
                ->has('checklist.0.label')
                ->has('checklist.0.group'));
    });

    it('shows a recorded result and note', function () {
        $user = User::factory()->create();
        $machine = Machine::factory()->for($user)->create(['os' => 'macOS', 'device_type' => 'laptop']);
        $key = (new ChecklistResolver)->for($machine)[0]['key'];
        ChecklistEntry::factory()->for($machine)->create(['item_key' => $key, 'outcome' => ChecklistOutcome::Fail, 'note' => 'cracked corner']);

        actingAs($user)->get("/machines/{$machine->id}")
            ->assertInertia(fn ($page) => $page
                ->where('checklist.0.key', $key)
                ->where('checklist.0.outcome', 'fail')
                ->where('checklist.0.note', 'cracked corner'));
    });

    it('does not show results that belong to another machine', function () {
        $user = User::factory()->create();
        $machine = Machine::factory()->for($user)->create(['os' => 'macOS', 'device_type' => 'laptop']);
        $key = (new ChecklistResolver)->for($machine)[0]['key'];
        ChecklistEntry::factory()->create(['item_key' => $key, 'outcome' => ChecklistOutcome::Pass]);

        actingAs($user)->get("/machines/{$machine->id}")
            ->assertInertia(fn ($page) => $page->where('checklist.0.outcome', null));
    });
});

describe('PUT /machines/{machine}/checklist/{item}', function () {
    function checklistKey(Machine $machine): string
    {
        return (new ChecklistResolver)->for($machine)[0]['key'];
    }

    it('records a result with a note', function () {
        $user = User::factory()->create();
        $machine = Machine::factory()->for($user)->create(['os' => 'macOS', 'device_type' => 'laptop']);
        $key = checklistKey($machine);

        actingAs($user)->put("/machines/{$machine->id}/checklist/{$key}", ['outcome' => 'pass', 'note' => 'looks fine'])
            ->assertRedirect("/machines/{$machine->id}");

        assertDatabaseHas('checklist_entries', ['machine_id' => $machine->id, 'item_key' => $key, 'outcome' => 'pass', 'note' => 'looks fine']);
    });

    it('records each of the three outcomes', function (string $outcome) {
        $user = User::factory()->create();
        $machine = Machine::factory()->for($user)->create(['os' => 'macOS', 'device_type' => 'laptop']);
        $key = checklistKey($machine);

        actingAs($user)->put("/machines/{$machine->id}/checklist/{$key}", ['outcome' => $outcome])->assertRedirect();

        assertDatabaseHas('checklist_entries', ['item_key' => $key, 'outcome' => $outcome]);
    })->with(['pass', 'fail', 'na']);

    it('replaces the previous result instead of adding a row', function () {
        $user = User::factory()->create();
        $machine = Machine::factory()->for($user)->create(['os' => 'macOS', 'device_type' => 'laptop']);
        $key = checklistKey($machine);

        actingAs($user)->put("/machines/{$machine->id}/checklist/{$key}", ['outcome' => 'pass', 'note' => 'first']);
        actingAs($user)->put("/machines/{$machine->id}/checklist/{$key}", ['outcome' => 'fail', 'note' => 'second']);

        assertDatabaseCount('checklist_entries', 1);
        assertDatabaseHas('checklist_entries', ['item_key' => $key, 'outcome' => 'fail', 'note' => 'second']);
    });

    it('clears a result when the outcome is empty', function () {
        $user = User::factory()->create();
        $machine = Machine::factory()->for($user)->create(['os' => 'macOS', 'device_type' => 'laptop']);
        $key = checklistKey($machine);
        ChecklistEntry::factory()->for($machine)->create(['item_key' => $key]);

        actingAs($user)->put("/machines/{$machine->id}/checklist/{$key}", ['outcome' => null, 'note' => 'ignored'])
            ->assertRedirect();

        assertDatabaseMissing('checklist_entries', ['machine_id' => $machine->id, 'item_key' => $key]);
    });

    it('clearing something that was never set is harmless', function () {
        $user = User::factory()->create();
        $machine = Machine::factory()->for($user)->create(['os' => 'macOS', 'device_type' => 'laptop']);
        $key = checklistKey($machine);

        actingAs($user)->put("/machines/{$machine->id}/checklist/{$key}", ['outcome' => null])->assertRedirect();

        assertDatabaseCount('checklist_entries', 0);
    });

    it('rejects an item that is not on this machine’s checklist', function () {
        $user = User::factory()->create();
        $machine = Machine::factory()->for($user)->create(['os' => 'macOS', 'device_type' => 'laptop']);

        actingAs($user)->put("/machines/{$machine->id}/checklist/secure_boot_tpm", ['outcome' => 'pass'])->assertNotFound();
        actingAs($user)->put("/machines/{$machine->id}/checklist/nonsense", ['outcome' => 'pass'])->assertNotFound();

        assertDatabaseCount('checklist_entries', 0);
    });

    it('rejects an unknown outcome', function () {
        $user = User::factory()->create();
        $machine = Machine::factory()->for($user)->create(['os' => 'macOS', 'device_type' => 'laptop']);
        $key = checklistKey($machine);

        actingAs($user)->put("/machines/{$machine->id}/checklist/{$key}", ['outcome' => 'maybe'])
            ->assertSessionHasErrors('outcome');
    });

    it('rejects a note that is too long or not text', function () {
        $user = User::factory()->create();
        $machine = Machine::factory()->for($user)->create(['os' => 'macOS', 'device_type' => 'laptop']);
        $key = checklistKey($machine);

        actingAs($user)->put("/machines/{$machine->id}/checklist/{$key}", ['outcome' => 'pass', 'note' => str_repeat('a', 1001)])
            ->assertSessionHasErrors('note');
        actingAs($user)->put("/machines/{$machine->id}/checklist/{$key}", ['outcome' => 'pass', 'note' => ['x']])
            ->assertSessionHasErrors('note');
    });

    it('stores a whitespace only note as no note', function () {
        $user = User::factory()->create();
        $machine = Machine::factory()->for($user)->create(['os' => 'macOS', 'device_type' => 'laptop']);
        $key = checklistKey($machine);

        actingAs($user)->put("/machines/{$machine->id}/checklist/{$key}", ['outcome' => 'pass', 'note' => "  \n "]);

        expect(ChecklistEntry::query()->sole()->note)->toBeNull();
    });

    it('forbids another user’s machine', function () {
        $machine = Machine::factory()->create(['os' => 'macOS', 'device_type' => 'laptop']);
        $key = checklistKey($machine);

        actingAs(User::factory()->create())->put("/machines/{$machine->id}/checklist/{$key}", ['outcome' => 'pass'])->assertForbidden();

        assertDatabaseCount('checklist_entries', 0);
    });

    it('requires authentication', function () {
        $machine = Machine::factory()->create(['os' => 'macOS', 'device_type' => 'laptop']);
        $key = checklistKey($machine);

        $this->put("/machines/{$machine->id}/checklist/{$key}", ['outcome' => 'pass'])->assertRedirect('/login');
    });
});
