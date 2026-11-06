<?php

declare(strict_types=1);

use App\Enums\MachineStatus;
use App\Enums\ShareLocale;
use App\Models\Client;
use App\Models\Machine;
use App\Models\Report;
use App\Services\Flags\FlagAdvisor;
use App\Services\Share\ShareLocaleResolver;
use Illuminate\Support\Facades\Lang;

use function Pest\Laravel\actingAs;

const ENGLISH_PHRASES = [
    'Looking good', 'Needs work', 'Last checked', 'What to do first', 'Not checked yet', 'Nothing flagged',
    'more to look at', 'No computers to show', 'Free space', 'Processor', 'This link is not available',
    'Something went wrong', 'Ask whoever sent it', 'Unnamed machine', 'just now', ' ago',
];

function dutchClient(?ShareLocale $locale = null): Client
{
    return Client::factory()->withShareLink()->create(['name' => 'Acme', 'locale' => $locale ?? ShareLocale::Dutch]);
}

function flaggedMachine(Client $client): Machine
{
    $machine = Machine::factory()->for($client->user)->create(['client_id' => $client->id, 'name' => 'Kassa', 'os' => 'Windows', 'device_type' => 'desktop', 'status' => MachineStatus::NeedsWork]);
    Report::factory()->for($machine)->create([
        'markdown' => "# Windows checkup: PC\n\n- Chip/CPU: Ryzen 5\n- Memory: 32 GB\n- Free disk: 5%\n\n## Things to look at\n",
        'flags' => ['Defender real-time protection is OFF.', 'A restart is pending.', 'FileVault is OFF.', 'Only 4 GB of RAM.', 'Odd thing.'],
        'created_at' => now()->subDays(3),
    ]);

    return $machine;
}

/**
 * @return array<string, string>
 */
function flattenedLang(string $locale): array
{
    $flat = [];

    foreach (glob(lang_path($locale.'/*.php')) ?: [] as $file) {
        $walk = function (array $items, string $prefix) use (&$walk, &$flat): void {
            foreach ($items as $key => $value) {
                is_array($value) ? $walk($value, "$prefix$key.") : $flat["$prefix$key"] = $value;
            }
        };
        $walk(require $file, basename($file, '.php').'.');
    }

    ksort($flat);

    return $flat;
}

/**
 * @return list<string>
 */
function scriptFlagSamples(): array
{
    $samples = [];
    $scripts = [
        'windows-checkup.ps1' => '/Add-Flag\s+(["\'])(.+?)\1(?=\s*(?:\}|$))/m',
        'mac-checkup.sh' => '/(?:\bflag\s+"|echo "FLAG: )((?:[^"\\\\]|\\\\.)*)"/',
    ];

    foreach ($scripts as $file => $pattern) {
        preg_match_all($pattern, (string) file_get_contents(base_path('scripts/'.$file)), $found);
        $texts = $file === 'windows-checkup.ps1' ? $found[2] : $found[1];

        foreach ($texts as $text) {
            $text = preg_replace('/@\([^()]*\)/', '7', $text) ?? $text;

            do {
                $text = preg_replace('/\$\([^()]*\)|\$\{[^}]*\}|\$[A-Za-z_][A-Za-z0-9_]*/', '7', $text, -1, $count);
            } while ($count > 0);

            $samples[] = str_replace(['`', '\\"'], ['', '"'], $text);
        }
    }

    return $samples;
}

describe('picking the language', function () {
    it('shows Dutch by default', function () {
        $client = dutchClient();
        flaggedMachine($client);

        $this->get("/c/{$client->share_token}")
            ->assertOk()
            ->assertSee('lang="nl"', false)
            ->assertInertia(fn ($page) => $page
                ->where('locale', 'nl')
                ->where('machines.0.top_issue.headline', 'Geen enkele virusscanner beschermt deze pc'));
    });

    it('uses the language set on the client', function () {
        $client = dutchClient(ShareLocale::English);
        flaggedMachine($client);

        $this->get("/c/{$client->share_token}")
            ->assertSee('lang="en"', false)
            ->assertInertia(fn ($page) => $page
                ->where('locale', 'en')
                ->where('machines.0.top_issue.headline', 'No antivirus is protecting this PC'));
    });

    it('defaults a client created without a language to Dutch', function () {
        $client = Client::factory()->withShareLink()->create();

        expect($client->locale)->toBe(ShareLocale::Dutch)
            ->and($client->fresh()?->locale)->toBe(ShareLocale::Dutch);
    });

    it('lets the lang query beat the client default and remembers it in a cookie', function () {
        $client = dutchClient();
        flaggedMachine($client);

        $response = $this->get("/c/{$client->share_token}?lang=en");

        $response->assertInertia(fn ($page) => $page->where('locale', 'en'))
            ->assertCookie(ShareLocaleResolver::COOKIE, 'en');
        $cookie = $response->getCookie(ShareLocaleResolver::COOKIE, false);
        expect($cookie?->getPath())->toBe('/c')->and($cookie?->isHttpOnly())->toBeTrue();
    });

    it('keeps the choice on the next page through the cookie', function () {
        $client = dutchClient();
        $machine = flaggedMachine($client);

        $this->withCookie(ShareLocaleResolver::COOKIE, 'en')
            ->get("/c/{$client->share_token}/machines/{$machine->id}")
            ->assertInertia(fn ($page) => $page->where('locale', 'en')->where('report.advice.0.headline', 'No antivirus is protecting this PC'));
    });

    it('lets the query beat the cookie', function () {
        $client = dutchClient();

        $this->withCookie(ShareLocaleResolver::COOKIE, 'en')
            ->get("/c/{$client->share_token}?lang=nl")
            ->assertInertia(fn ($page) => $page->where('locale', 'nl'))
            ->assertCookie(ShareLocaleResolver::COOKIE, 'nl');
    });

    it('lets the cookie beat the client default', function () {
        $client = dutchClient(ShareLocale::English);

        $this->withCookie(ShareLocaleResolver::COOKIE, 'nl')
            ->get("/c/{$client->share_token}")
            ->assertInertia(fn ($page) => $page->where('locale', 'nl'));
    });

    it('ignores a language it does not offer', function (string $query) {
        $client = dutchClient(ShareLocale::English);

        $this->get("/c/{$client->share_token}?{$query}")
            ->assertOk()
            ->assertCookieMissing(ShareLocaleResolver::COOKIE)
            ->assertInertia(fn ($page) => $page->where('locale', 'en'));
    })->with(['fr', 'NL', '', 'nl-NL', '../../etc', 'nl%00', 'en%20', '%E2%80%AEnl', 'php://x']);

    it('ignores a language sent as an array', function () {
        $client = dutchClient(ShareLocale::English);

        $this->get("/c/{$client->share_token}?lang[]=nl")->assertInertia(fn ($page) => $page->where('locale', 'en'));
    });

    it('ignores an unknown cookie value', function () {
        $client = dutchClient(ShareLocale::English);

        $this->withCookie(ShareLocaleResolver::COOKIE, 'de')
            ->get("/c/{$client->share_token}")
            ->assertInertia(fn ($page) => $page->where('locale', 'en'));
    });

    it('ignores a cookie that is not encrypted', function () {
        $client = dutchClient(ShareLocale::English);

        $this->withUnencryptedCookie(ShareLocaleResolver::COOKIE, 'nl')
            ->get("/c/{$client->share_token}")
            ->assertInertia(fn ($page) => $page->where('locale', 'en'));
    });

    it('does not take a language from the Accept-Language header', function () {
        $client = dutchClient(ShareLocale::English);

        $this->get("/c/{$client->share_token}", ['Accept-Language' => 'nl-NL,nl;q=0.9'])
            ->assertInertia(fn ($page) => $page->where('locale', 'en'));
    });

    it('works on the machine page and the error pages too', function () {
        $client = dutchClient();
        $machine = flaggedMachine($client);

        $this->get("/c/{$client->share_token}/machines/{$machine->id}?lang=en")
            ->assertInertia(fn ($page) => $page->component('share/Machine')->where('locale', 'en'));
        $this->get('/c/'.str_repeat('a', 40))->assertNotFound()
            ->assertInertia(fn ($page) => $page->component('share/Unavailable')->where('locale', 'nl'));
        $this->get('/c/'.str_repeat('a', 40).'?lang=en')->assertNotFound()
            ->assertInertia(fn ($page) => $page->component('share/Unavailable')->where('locale', 'en'));
    });

    it('uses the client language on an error page under a valid link', function () {
        $client = dutchClient(ShareLocale::English);

        $this->get("/c/{$client->share_token}/machines/999999")->assertNotFound()
            ->assertInertia(fn ($page) => $page->component('share/Unavailable')->where('locale', 'en'));
        $this->get("/c/{$client->share_token}/nothing-here")->assertNotFound()
            ->assertInertia(fn ($page) => $page->component('share/Unavailable')->where('locale', 'en'));
    });

    it('survives an overlong link token without a lookup', function () {
        $this->get('/c/'.str_repeat('a', 300))->assertNotFound()
            ->assertInertia(fn ($page) => $page->where('locale', 'nl'));
    });
});

describe('isolation', function () {
    it('never shows another client\'s machine or data under this link in any language', function (string $lang) {
        $mine = dutchClient();
        $theirs = Client::factory()->withShareLink()->create(['name' => 'Theirs']);
        $other = flaggedMachine($theirs);

        $this->get("/c/{$mine->share_token}/machines/{$other->id}?lang={$lang}")
            ->assertNotFound()
            ->assertDontSee('Kassa')
            ->assertDontSee('Theirs');
        $this->get("/c/{$mine->share_token}?lang={$lang}")
            ->assertInertia(fn ($page) => $page->has('machines', 0));
    })->with(['nl', 'en']);

    it('does not let the language choice reveal whether a link exists', function () {
        $client = dutchClient();

        $known = $this->get("/c/{$client->share_token}/nothing?lang=en");
        $unknown = $this->get('/c/'.str_repeat('z', 40).'/nothing?lang=en');

        expect($known->getStatusCode())->toBe($unknown->getStatusCode());
    });
});

describe('Dutch on every share page', function () {
    it('leaves no known English phrase on the pages', function () {
        $client = dutchClient();
        $machine = flaggedMachine($client);
        Machine::factory()->for($client->user)->create(['client_id' => $client->id, 'name' => null, 'model' => null]);

        $pages = [
            "/c/{$client->share_token}",
            "/c/{$client->share_token}/machines/{$machine->id}",
            '/c/'.str_repeat('q', 40),
        ];

        foreach ($pages as $path) {
            $props = json_encode($this->get($path)->viewData('page')['props'] ?? [], JSON_THROW_ON_ERROR);

            foreach (ENGLISH_PHRASES as $phrase) {
                expect($props)->not->toContain($phrase);
            }
        }
    });

    it('gives every machine page flag a Dutch headline and advice', function () {
        $client = dutchClient();
        $machine = flaggedMachine($client);

        $advice = $this->get("/c/{$client->share_token}/machines/{$machine->id}")->viewData('page')['props']['report']['advice'];

        expect(array_column($advice, 'headline'))->toBe([
            'Geen enkele virusscanner beschermt deze pc',
            'Schijfversleuteling staat uit',
            'Slechts 4 GB werkgeheugen',
            'Een herstart is nog nodig',
            'Er is iets om naar te kijken',
        ]);
    });
});

describe('owner pages', function () {
    it('stay English whatever the share cookie or query says', function () {
        $client = dutchClient();
        $machine = flaggedMachine($client);

        actingAs($client->user)->withCookie(ShareLocaleResolver::COOKIE, 'nl')
            ->get("/machines/{$machine->id}?lang=nl")
            ->assertSee('lang="en"', false)
            ->assertCookieMissing(ShareLocaleResolver::COOKIE)
            ->assertInertia(fn ($page) => $page
                ->where('locale', 'en')
                ->where('report.advice.0.headline', 'No antivirus is protecting this PC'));
    });

    it('lets the owner set a client\'s share language and rejects anything else', function () {
        $client = dutchClient();

        actingAs($client->user)->put("/clients/{$client->id}", ['name' => 'Acme', 'locale' => 'en'])->assertRedirect();
        expect($client->fresh()?->locale)->toBe(ShareLocale::English);

        actingAs($client->user)->put("/clients/{$client->id}", ['name' => 'Acme', 'locale' => 'fr'])->assertSessionHasErrors('locale');
        actingAs($client->user)->put("/clients/{$client->id}", ['name' => 'Renamed'])->assertRedirect();
        expect($client->fresh()?->locale)->toBe(ShareLocale::English)->and($client->fresh()?->name)->toBe('Renamed');

        actingAs($client->user)->get("/clients/{$client->id}")
            ->assertInertia(fn ($page) => $page->where('client.locale', 'en'));
    });

    it('does not let another owner change the language', function () {
        $client = dutchClient();
        $stranger = Client::factory()->create()->user;

        actingAs($stranger)->put("/clients/{$client->id}", ['name' => 'Acme', 'locale' => 'en'])->assertForbidden();
        expect($client->fresh()?->locale)->toBe(ShareLocale::Dutch);
    });
});

describe('translation files', function () {
    it('have the same keys and placeholders in both languages', function () {
        $english = flattenedLang('en');
        $dutch = flattenedLang('nl');

        expect(array_keys($dutch))->toBe(array_keys($english));

        foreach ($english as $key => $text) {
            preg_match_all('/:[a-z]+/', (string) $text, $en);
            preg_match_all('/:[a-z]+/', (string) $dutch[$key], $nl);
            expect($nl[0])->toEqualCanonicalizing($en[0], $key);
        }
    });

    it('leave no flag text to fall back to English', function () {
        $fallbacks = array_values(array_filter(
            array_keys(flattenedLang('en')),
            fn (string $key): bool => ! Lang::hasForLocale($key, 'nl'),
        ));

        expect($fallbacks)->toBe([]);
    });

    it('do not copy the English text into the Dutch file', function () {
        $english = flattenedLang('en');
        $same = array_keys(array_filter(flattenedLang('nl'), fn ($text, $key): bool => $text === $english[$key], ARRAY_FILTER_USE_BOTH));

        expect($same)->toBe([]);
    });

    it('use no em-dash', function () {
        foreach (['en', 'nl'] as $locale) {
            foreach (flattenedLang($locale) as $text) {
                expect($text)->not->toContain('—')->not->toContain(' -- ');
            }
        }
    });
});

describe('flag advice in Dutch', function () {
    it('finds a rule for every flag text the two scan scripts can print', function () {
        $samples = scriptFlagSamples();

        expect(count($samples))->toBeGreaterThan(50);

        $unmatched = array_values(array_filter($samples, fn (string $flag): bool => (new FlagAdvisor)->advise($flag, 'nl')->advice === null));

        expect($unmatched)->toBe([]);
    });

    it('keeps the English wording when the locale is English', function () {
        app()->setLocale('en');

        expect((new FlagAdvisor)->advise('FileVault is OFF.')->headline)->toBe('Disk encryption is off');
    });

    it('keeps the grouping key the same in both languages', function () {
        $dutch = (new FlagAdvisor)->advise('Only about 5% of drive C: is free.', 'nl');

        expect($dutch->key)->toBe('Drive C: is almost full, only #% left');
    });
});
