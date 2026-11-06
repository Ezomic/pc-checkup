<?php

declare(strict_types=1);

use App\Enums\CheckupScript;
use App\Enums\ShareLocale;
use App\Models\Client;
use App\Models\Machine;
use App\Models\User;

use function Pest\Laravel\actingAs;

describe('GET /privacy', function () {
    it('needs no login and shows the placeholders while nothing is configured', function () {
        config(['pc-checkup.owner_name' => null, 'pc-checkup.privacy_email' => null]);

        $this->get('/privacy')->assertOk()->assertInertia(fn ($page) => $page
            ->component('share/Privacy')
            ->where('ownerName', '[owner name]')
            ->where('contactEmail', '[contact email]')
            ->where('contactConfigured', false));
    });

    it('treats empty and blank values as not configured', function (string $value) {
        config(['pc-checkup.owner_name' => $value, 'pc-checkup.privacy_email' => $value]);

        $this->get('/privacy')->assertInertia(fn ($page) => $page
            ->where('ownerName', '[owner name]')
            ->where('contactEmail', '[contact email]')
            ->where('contactConfigured', false));
    })->with(['', '   ']);

    it('shows the configured owner and contact email', function () {
        config(['pc-checkup.owner_name' => ' Robbin Thijssen ', 'pc-checkup.privacy_email' => 'privacy@example.test']);

        $this->get('/privacy')->assertInertia(fn ($page) => $page
            ->where('ownerName', 'Robbin Thijssen')
            ->where('contactEmail', 'privacy@example.test')
            ->where('contactConfigured', true));
    });

    it('lists the version of both scripts that clients download', function () {
        $this->get('/privacy')->assertInertia(fn ($page) => $page
            ->where('scripts.0', ['os' => 'windows', 'version' => CheckupScript::Windows->version()])
            ->where('scripts.1', ['os' => 'mac', 'version' => CheckupScript::Mac->version()]));
    });

    it('is in Dutch by default and follows ?lang', function () {
        $this->get('/privacy')->assertInertia(fn ($page) => $page->where('locale', 'nl'));
        $this->get('/privacy?lang=en')->assertInertia(fn ($page) => $page->where('locale', 'en'));
        $this->get('/privacy?lang=fr')->assertInertia(fn ($page) => $page->where('locale', 'nl'));
    });

    it('shows no owner data', function () {
        $user = User::factory()->create();
        Machine::factory()->for($user)->create(['name' => 'Secret-PC']);

        $this->get('/privacy')->assertOk()->assertDontSee('Secret-PC');
        actingAs($user)->get('/privacy')->assertOk()->assertDontSee('Secret-PC');
    });

    it('links to the script downloads that exist', function () {
        $this->get('/scripts/windows')->assertOk();
        $this->get('/scripts/mac')->assertOk();
    });
});

describe('the scripts open with a notice', function () {
    it('says what is read, that nothing changes, when it uploads, and where to read more, in both languages', function (CheckupScript $script) {
        $source = $script->contents();

        expect($source)->toContain('NL: Deze scan leest alleen')
            ->toContain('EN: This scan only reads')
            ->toContain('changes nothing')->toContain('wijzigt niets')
            ->toContain('/privacy')
            ->and(substr_count($source, 'This scan only reads'))->toBe(1);
    })->with([CheckupScript::Windows, CheckupScript::Mac]);

    it('prints the notice before the scan code is asked for', function (CheckupScript $script) {
        $source = $script->contents();

        $notice = strpos($source, 'NL: Deze scan leest alleen');
        $ask = $script === CheckupScript::Mac ? strpos($source, "\npcc_find_token \"\$PCC_CLI_TOKEN\"") : strpos($source, '$found = $null');

        expect($notice)->toBeInt()->and($ask)->toBeInt()->and($notice)->toBeLessThan($ask);
    })->with([CheckupScript::Windows, CheckupScript::Mac]);
});

describe('the client share pages', function () {
    it('are rendered by a layout that links to the privacy page', function () {
        $client = Client::factory()->withShareLink()->create(['locale' => ShareLocale::English]);

        $this->get("/c/{$client->share_token}")->assertOk()->assertInertia(fn ($page) => $page->component('share/Show'));
        expect((string) file_get_contents(resource_path('js/layouts/ShareLayout.vue')))->toContain('privacy-link');
    });
});
