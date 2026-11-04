<?php

declare(strict_types=1);

use App\Actions\Machines\ImportReport;
use App\Models\Machine;
use App\Models\Report;
use App\Models\ScanCode;
use App\Models\User;
use App\Services\Reports\ReportRedactor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;
use function Pest\Laravel\assertDatabaseCount;

const FAKE_FMM_LINE = "fmm-mobileme-token-FMM\tbplist00%dd%01%02%03%04%05%06%07%08fake.person@example.invalid%09Fake Person%0a";

/**
 * @return array{string, string} the fake bin directory and the nvram output it prints
 */
function fakeNvramBin(?string $nvram = null): array
{
    $nvram ??= implode("\n", [
        "boot-args\t-v keepsyms=1",
        "auto-boot\ttrue",
        "prev-lang:kbd\ten-US:0",
        "efi-boot-device-data\t%02%01%0c%00%d0%41%03%0a%00%00%00%00%01%01%06%00",
        "fmm-mobileme-token-FMM-BridgeHasAccount\t%00",
        FAKE_FMM_LINE,
        "Fmm-MobileMe-Token-Other\tsecret-value",
        "SystemAudioVolume\t%80",
    ])."\n";

    $dir = sys_get_temp_dir().'/pcc-nvram-'.bin2hex(random_bytes(4));
    mkdir($dir, 0777, true);
    file_put_contents($dir.'/nvram.txt', $nvram);
    file_put_contents($dir.'/nvram', "#!/bin/bash\ncat ".escapeshellarg($dir.'/nvram.txt')."\n");
    chmod($dir.'/nvram', 0755);
    file_put_contents($dir.'/failing', "#!/bin/bash\nexit 1\n");

    return [$dir, $nvram];
}

function runMacFunction(string $bin, string $function): string
{
    $result = Process::path(base_path())
        ->env(['PATH' => $bin.':/usr/bin:/bin'])
        ->run(['bash', '-c', 'source scripts/mac-checkup.sh; '.$function]);

    expect($result->errorOutput())->toBe('');

    return $result->output();
}

function leakyReport(string $extra, string $serial = 'C02SECRET0001'): string
{
    return reportMarkdown(['Serial' => $serial])."\n## Security: secure boot and firmware\n\n```\n".$extra."\n```\n";
}

describe('the Mac script', function () {
    it('prints only allowlisted firmware facts and never a token line', function () {
        [$bin] = fakeNvramBin();

        $output = runMacFunction($bin, 'pcc_firmware_facts');

        expect($output)->toBe("boot-args: -v keepsyms=1\nauto-boot: true\nprev-lang:kbd: en-US:0\n")
            ->and(strtolower($output))->not->toContain('token')->not->toContain('mobileme')->not->toContain('bplist')
            ->not->toContain('example.invalid')->not->toContain('fake person');
    });

    it('drops an allowlisted key whose value is a binary blob', function () {
        [$bin] = fakeNvramBin("boot-args\tbplist00%dd%01\nauto-boot\ttrue\n");

        expect(runMacFunction($bin, 'pcc_firmware_facts'))->toBe("auto-boot: true\n");
    });

    it('says Find My is on when the key exists, without printing it', function () {
        [$bin] = fakeNvramBin();

        $output = runMacFunction($bin, 'pcc_findmy_status');

        expect($output)->toBe("Find My Mac: on\n");
    });

    it('says Find My is off when the key is missing', function () {
        [$bin] = fakeNvramBin("boot-args\t-v\nfmm-mobileme-token-FMM-BridgeHasAccount\t%00\n");

        expect(runMacFunction($bin, 'pcc_findmy_status'))->toBe("Find My Mac: off\n");
    });

    it('says it could not check when nvram prints nothing', function () {
        [$bin] = fakeNvramBin('');

        expect(runMacFunction($bin, 'pcc_findmy_status'))->toBe("Find My Mac: could not check\n");
    });

    it('says it could not check when nvram is not there', function () {
        [$bin] = fakeNvramBin();
        rename($bin.'/nvram', $bin.'/nvram.off');

        expect(runMacFunction($bin, 'pcc_findmy_status'))->toBe("Find My Mac: could not check\n");
    });

    it('keeps the token out of both report sections', function () {
        [$bin] = fakeNvramBin();
        file_put_contents($bin.'/system_profiler', "#!/bin/bash\necho '      Activation Lock Status: Disabled'\n");
        chmod($bin.'/system_profiler', 0755);

        $output = runMacFunction($bin, 'pcc_firmware_report; pcc_findmy_report');

        expect(strtolower($output))->not->toContain('token')->not->toContain('mobileme')->not->toContain('bplist')
            ->and($output)->toContain('Activation Lock Status: Disabled')->toContain('Find My Mac: on')->toContain('boot-args: -v keepsyms=1');
    });

    it('does not read the token anywhere else in the script', function () {
        $script = (string) file_get_contents(base_path('scripts/mac-checkup.sh'));

        expect(preg_match_all('/nvram -p/', $script))->toBe(2)
            ->and($script)->not->toMatch('/sh_section[^\n]*nvram/');
    });
});

describe('ReportRedactor', function () {
    it('removes secret lines and keeps everything else', function (string $line, bool $removed) {
        $redacted = (new ReportRedactor)->redact("before\n{$line}\nafter");

        expect($redacted)->toBe($removed ? "before\n[removed]\nafter" : "before\n{$line}\nafter");
    })->with([
        'the nvram token line' => [rtrim(FAKE_FMM_LINE), true],
        'the bridge account line' => ["fmm-mobileme-token-FMM-BridgeHasAccount\t%00", true],
        'mixed case key' => ["FMM-MobileMe-TOKEN-x\tvalue", true],
        'authtoken with equals' => ['authToken = "abc"', true],
        'password with colon' => ['Password: hunter2', true],
        'indented secret' => ['    client_secret: abc', true],
        'bulleted secret' => ['- api_secret: abc', true],
        'quoted key' => ['"mobileme_id": "abc"', true],
        'bplist anywhere in the line' => ['something odd BPLIST00 data', true],
        'percent encoded blob without a key' => ['value %dd%01%02%03%04%05%06%07%08', true],
        'prose about tokens' => ['Number of tokens: 5', false],
        'key that only starts like a token' => ['Tokens in use: 5', false],
        'secret in the middle of a sentence' => ['The secret: is out', false],
        'a short percent value' => ["csr-active-config\t%7f%00%00%00", false],
        'ordinary fact' => ['boot-args: -v', false],
        'blank line' => ['', false],
        'heading' => ['## Passwords and sign-in', false],
    ]);

    it('removes a secret inside a code fence', function () {
        $redacted = (new ReportRedactor)->redact("```\n".rtrim(FAKE_FMM_LINE)."\nok\n```");

        expect($redacted)->toBe("```\n[removed]\nok\n```");
    });

    it('treats CRLF like a newline', function () {
        $redacted = (new ReportRedactor)->redact("ok\r\npassword = x\r\nok\r\n");

        expect($redacted)->toBe("ok\n[removed]\nok\n");
    });

    it('copes with a very long line', function () {
        $long = str_repeat('a', 1000000);
        $secret = 'password:'.str_repeat('b', 1000000);

        expect((new ReportRedactor)->redact("{$long}\n{$secret}"))->toBe("{$long}\n[removed]");
    });

    it('keeps a very long run of one word that is not a secret', function () {
        $long = str_repeat('token', 100000).' is long';

        expect((new ReportRedactor)->redact($long))->toBe($long);
    });

    it('leaves an empty report empty', function () {
        expect((new ReportRedactor)->redact(''))->toBe('');
    });

    it('is idempotent', function () {
        $once = (new ReportRedactor)->redact('password: x');

        expect((new ReportRedactor)->redact($once))->toBe($once);
    });
});

describe('importing a report with a token in it', function () {
    it('stores the redacted text through the import page', function () {
        $user = User::factory()->create();

        actingAs($user)->post('/imports', ['text' => leakyReport(FAKE_FMM_LINE."\nboot-args\t-v")])->assertRedirect('/machines');

        $stored = Report::query()->sole()->markdown;

        expect($stored)->toContain("[removed]\nboot-args\t-v")->not->toContain('bplist00')->not->toContain('example.invalid')->not->toContain('mobileme');
    });

    it('stores the redacted text from an uploaded file with CRLF and a byte order mark', function () {
        $user = User::factory()->create();
        $text = "\xEF\xBB\xBF".str_replace("\n", "\r\n", leakyReport(rtrim(FAKE_FMM_LINE)));
        $file = UploadedFile::fake()->createWithContent('scan.md', $text);

        actingAs($user)->post('/imports', ['file' => $file])->assertRedirect('/machines');

        expect(Report::query()->sole()->markdown)->not->toContain('bplist00')->toContain('[removed]');
    });

    it('stores the redacted text from a scan code upload', function () {
        $user = User::factory()->create();
        ScanCode::factory()->for($user)->withCode('123456')->create();

        $this->postJson('/api/scans', ['code' => '123456', 'markdown' => leakyReport(FAKE_FMM_LINE)])->assertCreated();

        expect(Report::query()->sole()->markdown)->not->toContain('bplist00')->not->toContain('example.invalid');
    });

    it('does not echo the token in the response', function () {
        $user = User::factory()->create();
        ScanCode::factory()->for($user)->withCode('123456')->create();

        $response = $this->postJson('/api/scans', ['code' => '123456', 'markdown' => leakyReport(FAKE_FMM_LINE)]);

        expect($response->getContent())->not->toContain('bplist00')->not->toContain('example.invalid');
    });

    it('still reads the flags and identity from a report whose token line was removed', function () {
        $user = User::factory()->create();

        $report = (new ImportReport)->handle($user, leakyReport(FAKE_FMM_LINE));

        expect($report->flags)->toBe(['Battery has 900 cycles (high).'])
            ->and(Machine::query()->sole()->serial)->toBe('C02SECRET0001');
    });

    it('detects a re-upload of the same scan as a duplicate', function () {
        $user = User::factory()->create();
        $text = leakyReport(FAKE_FMM_LINE);

        $first = (new ImportReport)->handle($user, $text);
        $second = (new ImportReport)->handle($user, str_replace("\n", "\r\n", $text));

        expect($second->id)->toBe($first->id);
        assertDatabaseCount('reports', 1);
    });

    it('keeps the checksum of the text as uploaded, so it matches what the scanner produced', function () {
        $user = User::factory()->create();
        $text = leakyReport(FAKE_FMM_LINE);

        $report = (new ImportReport)->handle($user, $text);

        expect($report->checksum)->toBe(hash('sha256', trim($text)."\n"));
    });

    it('leaves prose that mentions tokens alone', function () {
        $user = User::factory()->create();

        (new ImportReport)->handle($user, leakyReport('Number of tokens: 5'));

        expect(Report::query()->sole()->markdown)->toContain('Number of tokens: 5');
    });

    it('imports an empty body as before', function () {
        actingAs(User::factory()->create())->post('/imports', ['text' => ''])->assertSessionHasErrors();

        assertDatabaseCount('reports', 0);
    });
});

describe('reports:redact', function () {
    function storedLeak(bool $trashed = false): Report
    {
        $report = Report::factory()->create(['markdown' => "# Mac checkup: Test\n\n".rtrim(FAKE_FMM_LINE)."\nok\n"]);

        if ($trashed) {
            $report->delete();
        }

        return $report;
    }

    it('only counts with --dry-run', function () {
        $report = storedLeak();

        artisan('reports:redact', ['--dry-run' => true])
            ->expectsOutputToContain('1 of 1 reports contain secrets')
            ->assertSuccessful();

        expect($report->fresh()?->markdown)->toContain('bplist00');
    });

    it('redacts stored reports including soft deleted ones and keeps their checksum', function () {
        $live = storedLeak();
        $gone = storedLeak(trashed: true);
        $clean = Report::factory()->create();
        $checksum = $live->checksum;
        $updatedAt = $live->updated_at;

        artisan('reports:redact')->expectsOutputToContain('Redacted 2 of 3 reports')->assertSuccessful();

        expect($live->fresh()?->markdown)->toBe("# Mac checkup: Test\n\n[removed]\nok\n")
            ->and($live->fresh()?->checksum)->toBe($checksum)
            ->and($live->fresh()?->updated_at?->equalTo($updatedAt))->toBeTrue()
            ->and(Report::withTrashed()->find($gone->id)?->markdown)->not->toContain('bplist00')
            ->and($clean->fresh()?->markdown)->toBe($clean->markdown);
    });

    it('is idempotent', function () {
        storedLeak();

        artisan('reports:redact')->assertSuccessful();
        artisan('reports:redact')->expectsOutputToContain('Redacted 0 of 1 reports')->assertSuccessful();
        artisan('reports:redact', ['--dry-run' => true])->expectsOutputToContain('0 of 1 reports contain secrets')->assertSuccessful();
    });

    it('copes with no reports', function () {
        artisan('reports:redact')->expectsOutputToContain('Redacted 0 of 0 reports')->assertSuccessful();
    });
});
