<?php

declare(strict_types=1);

use App\Services\Reports\ReportBackups;
use App\Services\Reports\ReportSections;

function bkCard(string $lines, string $heading = 'Backup freshness'): ?array
{
    return (new ReportBackups)->card(ReportSections::parse("# Checkup\n\n- Format: 1\n\n## {$heading}\n\n```\n{$lines}\n```\n"));
}

/**
 * @return array<string, array{value: string, tone: string}>
 */
function bkRows(string $lines): array
{
    $rows = [];

    foreach (bkCard($lines)['rows'] ?? [] as $row) {
        $rows[$row['label']] = ['value' => $row['value'], 'tone' => $row['tone']];
    }

    return $rows;
}

const BK_NOW = "format: 1\nscanned: 2026-11-06\n";

describe('the verdict on a Mac', function () {
    it('calls a backup of the last week good and says how long ago', function () {
        $rows = bkRows(BK_NOW."timemachine: set up\ntimemachine-last: 2026-11-04\nicloud-folders: none");

        expect($rows['Backup'])->toBe(['value' => 'Last backup 2 days ago', 'tone' => 'good'])
            ->and($rows['Time Machine'])->toBe(['value' => 'Last backup 2026-11-04 (2 days ago)', 'tone' => 'good'])
            ->and($rows['iCloud Drive Desktop and Documents'])->toBe(['value' => 'Not turned on', 'tone' => 'neutral']);
    });

    it('draws the lines at 7, 30 and 31 days', function (int $days, string $tone) {
        $date = (new DateTimeImmutable('2026-11-06'))->modify("-{$days} days")->format('Y-m-d');

        expect(bkRows(BK_NOW."timemachine: set up\ntimemachine-last: {$date}")['Backup']['tone'])->toBe($tone);
    })->with([[0, 'good'], [7, 'good'], [8, 'neutral'], [30, 'neutral'], [31, 'bad'], [900, 'bad']]);

    it('says today and yesterday in words', function () {
        expect(bkRows(BK_NOW."timemachine: set up\ntimemachine-last: 2026-11-06")['Backup']['value'])->toBe('Last backup today')
            ->and(bkRows(BK_NOW."timemachine: set up\ntimemachine-last: 2026-11-05")['Backup']['value'])->toBe('Last backup yesterday');
    });

    it('says No backup found, in bad, when nothing is set up and everything could be read', function () {
        $rows = bkRows(BK_NOW."timemachine: not set up\ntimemachine-last: none\nicloud-folders: none");

        expect($rows['Backup'])->toBe(['value' => 'No backup found', 'tone' => 'bad'])
            ->and($rows['Time Machine'])->toBe(['value' => 'Not set up', 'tone' => 'neutral']);
    });

    it('calls a set up Time Machine that never ran a bad sign', function () {
        $rows = bkRows(BK_NOW."timemachine: set up\ntimemachine-last: none");

        expect($rows['Backup'])->toBe(['value' => 'Set up, but no backup has been made yet', 'tone' => 'bad'])
            ->and($rows['Time Machine'])->toBe(['value' => 'Set up, no backup yet', 'tone' => 'bad']);
    });

    it('does not call it bad when Time Machine is set up but its date could not be read', function () {
        $rows = bkRows(BK_NOW."timemachine: set up\ntimemachine-last: could not check");

        expect($rows['Backup'])->toBe(['value' => 'Set up, but the date of the last backup could not be read', 'tone' => 'neutral'])
            ->and($rows['Time Machine'])->toBe(['value' => 'Set up', 'tone' => 'neutral']);
    });

    it('is not bad when Desktop and Documents are kept in iCloud', function () {
        $rows = bkRows(BK_NOW."timemachine: not set up\ntimemachine-last: none\nicloud-folders: Desktop, Documents");

        expect($rows['Backup'])->toBe(['value' => 'Your folders are kept in the cloud, but no backup copy was found', 'tone' => 'neutral'])
            ->and($rows['iCloud Drive Desktop and Documents']['value'])->toBe('Desktop, Documents');
    });

    it('says nothing bad when part of it could not be checked', function () {
        $rows = bkRows(BK_NOW."timemachine: not set up\ntimemachine-last: none\nicloud-folders: could not check");

        expect($rows)->not->toHaveKey('Backup')
            ->and($rows)->not->toHaveKey('iCloud Drive Desktop and Documents')
            ->and($rows['Time Machine']['value'])->toBe('Not set up');
    });

    it('hides the card when nothing could be checked', function () {
        expect(bkCard(BK_NOW."timemachine: could not check\ntimemachine-last: could not check\nicloud-folders: could not check"))->toBeNull();
    });
});

describe('the verdict on Windows', function () {
    it('uses File History and lists the OneDrive folders and the restore point', function () {
        $rows = bkRows(BK_NOW."filehistory: set up\nfilehistory-last: 2026-10-20\nonedrive-folders: Desktop,Documents,Pictures\nrestorepoint-last: 2026-10-01");

        expect($rows['Backup'])->toBe(['value' => 'Last backup 17 days ago', 'tone' => 'neutral'])
            ->and($rows['File History']['value'])->toBe('Last backup 2026-10-20 (17 days ago)')
            ->and($rows['OneDrive folder backup']['value'])->toBe('Desktop, Documents, Pictures')
            ->and($rows['Last restore point'])->toBe(['value' => '2026-10-01 (36 days ago)', 'tone' => 'neutral']);
    });

    it('takes the newest of the dated backups', function () {
        $rows = bkRows(BK_NOW."timemachine: set up\ntimemachine-last: 2026-05-01\nfilehistory: set up\nfilehistory-last: 2026-11-01");

        expect($rows['Backup']['value'])->toBe('Last backup 5 days ago');
    });

    it('says No backup found when File History and OneDrive are both off', function () {
        $rows = bkRows(BK_NOW."filehistory: not set up\nfilehistory-last: none\nonedrive-folders: none\nrestorepoint-last: none");

        expect($rows['Backup'])->toBe(['value' => 'No backup found', 'tone' => 'bad'])
            ->and($rows['Last restore point']['value'])->toBe('None found')
            ->and($rows['OneDrive folder backup']['value'])->toBe('Not turned on');
    });

    it('does not say No backup found when the OneDrive folders could not be read', function () {
        $rows = bkRows(BK_NOW."filehistory: not set up\nfilehistory-last: none\nonedrive-folders: could not check");

        expect($rows)->not->toHaveKey('Backup');
    });

    it('keeps only the folders it knows and drops the rest of a hostile list', function () {
        $rows = bkRows(BK_NOW."onedrive-folders: desktop, C:\\Secret, <b>x</b>, Pictures, desktop\nfilehistory: not set up");

        expect($rows['OneDrive folder backup']['value'])->toBe('Desktop, Pictures');
    });

    it('treats a list with no known folder as unreadable', function () {
        expect(bkRows(BK_NOW."onedrive-folders: C:\\Secret\nfilehistory: not set up"))->not->toHaveKey('OneDrive folder backup');
    });

    it('notes what could not be checked and why sync is not a backup', function () {
        $notes = bkCard(BK_NOW."filehistory: could not check\nonedrive-folders: Desktop\nrestorepoint-last: none")['notes'];

        expect($notes[0])->toBe('The scan could not check: File History.')
            ->and($notes[1])->toContain('not a dated backup');
    });
});

describe('unreadable and hostile input', function () {
    it('shows no card for a report without the section or without a scan date', function (string $lines) {
        expect(bkCard($lines))->toBeNull();
    })->with([
        'no date' => ["format: 1\ntimemachine: set up\ntimemachine-last: 2026-11-04"],
        'invalid date' => ["scanned: 2026-02-30\ntimemachine: set up\ntimemachine-last: 2026-11-04"],
        'old date' => ["scanned: 1999-01-01\ntimemachine: set up\ntimemachine-last: 1999-01-01"],
        'text' => ["scanned: yesterday\ntimemachine: set up"],
        'empty' => [''],
    ]);

    it('shows no card for an old report that has no such section', function () {
        expect(bkCard("scanned: 2026-11-06\ntimemachine: set up", 'Backup status'))->toBeNull();
    });

    it('ignores a last backup date that cannot be right', function (string $last) {
        $rows = bkRows(BK_NOW."timemachine: set up\ntimemachine-last: {$last}");

        expect($rows['Backup']['tone'])->toBe('neutral')
            ->and($rows['Time Machine']['value'])->toBe('Set up');
    })->with(['2026-02-30', '2026-12-25', '1970-01-01', 'last tuesday', '2026-11-04; rm -rf', "2026-11\u{0}-04", '']);

    it('treats a date one day ahead of the scan as today, for clock and time zone differences', function () {
        expect(bkRows(BK_NOW."timemachine: set up\ntimemachine-last: 2026-11-07")['Backup']['value'])->toBe('Last backup today');
    });

    it('reads keys and words in any case with stray spaces', function () {
        $rows = bkRows(BK_NOW."TIMEMACHINE:   SET UP  \nTIMEMACHINE-LAST:   2026-11-05  ");

        expect($rows['Backup']['value'])->toBe('Last backup yesterday');
    });

    it('ignores a restore point date that is not a date', function () {
        expect(bkRows(BK_NOW."filehistory: not set up\nfilehistory-last: none\nonedrive-folders: none\nrestorepoint-last: soon"))->not->toHaveKey('Last restore point');
    });

    it('never puts text from the report into a note or a row except known words and dates', function () {
        $card = bkCard(BK_NOW."timemachine: <script>alert(1)</script>\nicloud-folders: <img src=x>\nrestorepoint-last: <b>2026-10-01</b>");

        expect(json_encode($card))->not->toContain('<script')->not->toContain('<img')->not->toContain('<b>');
    });
});
