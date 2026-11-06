<?php

declare(strict_types=1);

use App\Actions\Machines\ImportReport;
use App\Models\Machine;
use App\Models\Report;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;

function reportWithUser(string $name = 'robbin'): string
{
    return "# Mac checkup: Test-MacBook\n\n- Format: 1\n- OS: macOS\n- Device type: laptop\n- Serial: C02MASKTEST01\n- Model: MacBook\n\n"
        ."## Performance: biggest folders in home\n\n```\n38G\t/Users/{$name}/Library\n```\n\n"
        ."## Cron jobs\n\n```\ncrontab: no crontab for {$name}\n```\n";
}

describe('on import', function () {
    it('stores the report with the user name masked and everything else intact', function () {
        $report = (new ImportReport)->handle(User::factory()->create(), reportWithUser());

        expect($report->markdown)->toContain('/Users/<user>/Library')->toContain('no crontab for <user>')
            ->not->toContain('robbin')->toContain('- Serial: C02MASKTEST01');
    });

    it('keeps the checksum of the text as the scanner wrote it', function () {
        $text = reportWithUser();

        $report = (new ImportReport)->handle(User::factory()->create(), $text);

        expect($report->checksum)->toBe(hash('sha256', trim($text)."\n"));
    });

    it('still sees a re-upload as a duplicate', function () {
        $user = User::factory()->create();
        $first = (new ImportReport)->handle($user, reportWithUser());
        $second = (new ImportReport)->handle($user, str_replace("\n", "\r\n", reportWithUser()));

        expect($second->id)->toBe($first->id)->and(Report::query()->count())->toBe(1);
    });

    it('masks it for an import through the paste page too, and never shows it to the owner', function () {
        $user = User::factory()->create();

        actingAs($user)->post('/imports', ['text' => reportWithUser()])->assertSessionHasNoErrors();

        $machine = Machine::query()->sole();
        actingAs($user)->get("/machines/{$machine->id}")->assertOk()->assertDontSee('robbin');
    });

    it('masks the Windows profile name in a CRLF report', function () {
        $text = str_replace("\n", "\r\n", "# Windows checkup: TEST-PC\n\n- Format: 1\n- OS: Windows\n- Device type: desktop\n- Serial: WINMASK0001\n- Model: Test\n\n## Startup programs\n\n```\nC:\\Users\\Robbin\\AppData\\x.exe\n```\n");

        $report = (new ImportReport)->handle(User::factory()->create(), $text);

        expect($report->markdown)->toContain('C:\\Users\\<user>\\AppData\\x.exe')->not->toContain('Robbin')->not->toContain("\r");
    });
});

describe('reports:redact', function () {
    it('counts reports that hold a user name with --dry-run and changes nothing', function () {
        $report = Report::factory()->create(['markdown' => reportWithUser()]);

        artisan('reports:redact', ['--dry-run' => true])->expectsOutputToContain('1 of 1 reports contain secrets or a user name')->assertSuccessful();

        expect($report->fresh()?->markdown)->toContain('robbin');
    });

    it('masks stored reports, soft deleted ones included, keeps the checksum and is idempotent', function () {
        $live = Report::factory()->create(['markdown' => reportWithUser()]);
        $gone = Report::factory()->create(['markdown' => reportWithUser('anna')]);
        $gone->delete();
        $checksum = $live->checksum;
        $updatedAt = $live->updated_at;

        artisan('reports:redact')->expectsOutputToContain('Redacted 2 of 2 reports')->assertSuccessful();

        expect($live->fresh()?->markdown)->not->toContain('robbin')->toContain('/Users/<user>/Library')
            ->and($live->fresh()?->checksum)->toBe($checksum)
            ->and($live->fresh()?->updated_at?->equalTo($updatedAt))->toBeTrue()
            ->and(Report::withTrashed()->find($gone->id)?->markdown)->not->toContain('anna');

        artisan('reports:redact')->expectsOutputToContain('Redacted 0 of 2 reports')->assertSuccessful();
    });
});
