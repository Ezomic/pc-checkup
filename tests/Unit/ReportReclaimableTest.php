<?php

declare(strict_types=1);

use App\Services\Reports\ReportDetails;
use App\Services\Reports\ReportFacts;

const RC_LINES = "scan: complete\nseconds: 7\nlimit: 60\nformat: 1\n"
    ."reclaim|trash|2097152|14\nreclaim|caches|5242880|63\nreclaim|installers|1048576|3\n"
    ."reclaim|backups|10485760|2\nreclaim|derived|524288|4\nsnapshots|3\n"
    ."file|Holiday film.mkv|8388608|Videos|Movies\nfile|backup.zip|4194304|Archives|Downloads\n";

function rcDoc(string $lines): string
{
    return "# Mac checkup: Test\n\n- Format: 1\n\n## Space you can get back\n\n```\n{$lines}\n```\n";
}

/**
 * @return array{id: string, title: string, summary: string|null, rows: list<mixed>, list: null, bars: list<array{title: string, items: list<array{label: string, value: string, detail: string|null, share: int}>}>, notes: list<string>}|null
 */
function rcCard(string $lines): ?array
{
    foreach ((new ReportDetails(new ReportFacts))->cards(rcDoc($lines), [], 'laptop') as $card) {
        if ($card['id'] === 'reclaimable') {
            return $card;
        }
    }

    return null;
}

/**
 * @return list<array{label: string, value: string, detail: string|null, share: int}>
 */
function rcItems(?array $card, string $title): array
{
    foreach ($card['bars'] ?? [] as $group) {
        if ($group['title'] === $title) {
            return $group['items'];
        }
    }

    return [];
}

describe('the card', function () {
    it('has its own id and title and comes after the storage card', function () {
        $markdown = rcDoc(RC_LINES)."\n## Disk usage\n\n```\nFilesystem Size Used Avail Capacity iused ifree %iused Mounted on\n/dev/disk1s1  100Gi  80Gi  20Gi  80%  100  200  33%  /\n```\n";
        $ids = array_column((new ReportDetails(new ReportFacts))->cards($markdown, [], 'laptop'), 'id');

        expect($ids)->toBe(['storage', 'reclaimable'])
            ->and(rcCard(RC_LINES)['title'])->toBe('Space you can get back')
            ->and(rcCard(RC_LINES)['rows'])->toBe([])
            ->and(rcCard(RC_LINES)['list'])->toBeNull();
    });

    it('adds up what can usually be cleared in one plain sentence, without the phone backups', function () {
        expect(rcCard(RC_LINES)['summary'])->toBe('About 8.5 GB can usually be cleared.');
    });

    it('lists the places biggest first with a bar, the count and plain advice', function () {
        expect(rcItems(rcCard(RC_LINES), 'What you can clear'))->toBe([
            ['label' => 'Old iPhone and iPad backups', 'value' => '10 GB', 'detail' => '2 backups. Check that you no longer need them before you delete one.', 'share' => 100],
            ['label' => 'App caches', 'value' => '5 GB', 'detail' => '63 items. Safe to clear: apps rebuild them when they need them.', 'share' => 50],
            ['label' => 'Trash', 'value' => '2 GB', 'detail' => '14 items. Empty the Trash. Look through it first, because emptying it cannot be undone.', 'share' => 20],
            ['label' => 'Installers in Downloads', 'value' => '1 GB', 'detail' => '3 files. Delete them after the app is installed. You can download them again if you need one.', 'share' => 10],
            ['label' => 'Xcode build files', 'value' => '512 MB', 'detail' => '4 items. Safe to delete: Xcode builds them again when you open a project.', 'share' => 5],
        ]);
    });

    it('lists the biggest files with the size, the kind and the folder it is in', function () {
        expect(rcItems(rcCard(RC_LINES), 'Biggest files'))->toBe([
            ['label' => 'Holiday film.mkv', 'value' => '8 GB', 'detail' => 'Videos, in Movies', 'share' => 100],
            ['label' => 'backup.zip', 'value' => '4 GB', 'detail' => 'Archives, in Downloads', 'share' => 50],
        ]);
    });

    it('says what the snapshots are and that macOS frees them itself, without a size', function () {
        $notes = rcCard(RC_LINES)['notes'];

        expect($notes)->toContain('3 local Time Machine snapshots are kept on this Mac. macOS frees them itself when it needs the space, so there is nothing for you to do.')
            ->and(rcCard("scan: complete\nreclaim|trash|1024|1\nsnapshots|1")['notes'])->toContain('1 local Time Machine snapshot is kept on this Mac. macOS frees it itself when it needs the space, so there is nothing for you to do.');
    });

    it('explains the phone backups are left out of the total, and who sees the names', function () {
        $notes = rcCard(RC_LINES)['notes'];

        expect($notes)->toContain('Old iPhone and iPad backups are not counted in the total, because you may still need them.')
            ->toContain('Only you see the file names and folder names in this card: they are not on the pages you share with clients. The scan only looked, it deleted nothing.');
    });

    it('never tells anyone to delete system files', function () {
        $lines = "scan: complete\nreclaim|systemtemp|1048576|9\nreclaim|updates|2097152|4\nreclaim|windowsold|4194304|2\nreclaim|temp|1024|1\n";
        $details = array_column(rcItems(rcCard($lines), 'What you can clear'), 'detail', 'label');

        expect($details['Windows temporary files'])->toContain('Disk Cleanup')->not->toContain('Delete')
            ->and($details['Downloaded Windows updates'])->toContain('Disk Cleanup')
            ->and($details['Previous Windows version (Windows.old)'])->toContain('Disk Cleanup')
            ->and($details['Temporary files'])->toContain('Safe to clear');
    });

    it('names the Recycle Bin on Windows', function () {
        $items = rcItems(rcCard("scan: complete\nreclaim|recycle|1048576|2"), 'What you can clear');

        expect($items[0]['label'])->toBe('Recycle Bin')->and($items[0]['detail'])->toContain('Empty the Recycle Bin');
    });
});

describe('what stays hidden', function () {
    it('shows no card for a report without the section, an empty one or one with only zeros', function (string $markdown) {
        $ids = array_column((new ReportDetails(new ReportFacts))->cards($markdown, [], 'laptop'), 'id');

        expect($ids)->not->toContain('reclaimable');
    })->with([
        'an old report' => ["# Mac checkup: Old\n\n- Format: 1\n\n## Disk usage\n\n```\nnothing\n```\n"],
        'an empty section' => [rcDoc('')],
        'only the scan lines' => [rcDoc("scan: complete\nseconds: 1\nlimit: 60\nformat: 1")],
        'all zeros' => [rcDoc("scan: complete\nreclaim|trash|0|0\nreclaim|caches|0|0")],
        'only snapshots' => [rcDoc("scan: complete\nsnapshots|4")],
    ]);

    it('hides a place that is empty and shows no total when only files were found', function () {
        $card = rcCard("scan: complete\nreclaim|trash|0|0\nfile|a.bin|1024|Other|Documents");

        expect($card['summary'])->toBeNull()
            ->and(rcItems($card, 'What you can clear'))->toBe([])
            ->and(array_column($card['bars'], 'title'))->toBe(['Biggest files']);
    });

    it('shows no total when only the phone backups were found', function () {
        $card = rcCard("scan: complete\nreclaim|backups|1048576|1");

        expect($card['summary'])->toBeNull()->and(rcItems($card, 'What you can clear'))->toHaveCount(1);
    });
});

describe('lines that are not trusted', function () {
    it('drops sizes that are not a plain positive number', function (string $size) {
        expect(rcCard("scan: complete\nreclaim|trash|{$size}|1\nreclaim|caches|1024|1")['bars'][0]['items'])->toHaveCount(1);
    })->with(['n/a', '-5', '-5 MB', 'abc', '', '1e5', '1 2', '99999999999999999999', '1,2,3', ',5', '5,']);

    it('drops counts that are not a plain number and shows the row without a count', function (string $count) {
        $items = rcItems(rcCard("scan: complete\nreclaim|trash|1024|{$count}"), 'What you can clear');

        expect($items[0]['detail'])->toStartWith('Empty the Trash.');
    })->with(['n/a', '-3', '', '9999999999999999', '1,2,3']);

    it('ignores unknown places, lines with the wrong number of fields and extra whitespace', function () {
        $lines = "scan: complete\nreclaim|passwords|1024|1\nreclaim|trash|1024\nreclaim|trash|1024|1|extra\n  reclaim|caches|2048|2  \nreclaim|Trash|512|1\nfile|a.bin|1|Other\nfile|b.bin|2048|Other|x|y\n";
        $card = rcCard($lines);

        expect(array_column(rcItems($card, 'What you can clear'), 'label'))->toBe(['App caches', 'Trash'])
            ->and(rcItems($card, 'Biggest files'))->toBe([]);
    });

    it('takes the first line for a place that is listed twice', function () {
        $items = rcItems(rcCard("scan: complete\nreclaim|trash|1024|1\nreclaim|trash|4096|9"), 'What you can clear');

        expect($items)->toHaveCount(1)->and($items[0]['value'])->toBe('1 MB');
    });

    it('does not turn report text into anything but text', function () {
        $card = rcCard("scan: complete\nfile|<img src=x onerror=alert(1)>.bin|2048|Other|<b>Docs</b>");

        expect($card['bars'][0]['items'][0]['label'])->toBe('<img src=x onerror=alert(1)>.bin');
    });
});

describe('the scan flag', function () {
    it('says the numbers are a minimum when the scan stopped at its time limit', function () {
        $lines = "scan: partial (time limit of 60 seconds reached)\nreclaim|trash|1024|1";

        expect(rcCard($lines)['notes'])->toContain('The scan stopped at its time limit, so these sizes are a minimum and there may be more to clear.');
    });

    it('treats a missing scan line as partial and a complete one as complete', function () {
        expect(rcCard('reclaim|trash|1024|1')['notes'])->toContain('The scan stopped at its time limit, so these sizes are a minimum and there may be more to clear.')
            ->and(implode(' ', rcCard("scan: complete\nreclaim|trash|1024|1")['notes']))->not->toContain('time limit');
    });
});

describe('places that could not be looked at', function () {
    it('names them in a note, once, in plain words', function () {
        $lines = "scan: complete\nreclaim|caches|1024|1\nunreadable|trash\nunreadable|backups\nunreadable|Trash";

        expect(rcCard($lines)['notes'])->toContain('Could not be looked at, so they are not counted: Trash, Old iPhone and iPad backups.');
    });

    it('ignores an unknown place, a malformed line and a place that was measured after all', function () {
        $lines = "scan: complete\nreclaim|caches|1024|1\nunreadable|passwords\nunreadable|trash|extra\nunreadable|caches";

        expect(implode(' ', rcCard($lines)['notes']))->not->toContain('Could not be looked at');
    });

    it('shows no card when nothing else was found', function () {
        expect(rcCard("scan: complete\nunreadable|trash"))->toBeNull();
    });
});

describe('Dutch numbers', function () {
    it('reads sizes and counts with decimal and thousands commas', function () {
        $lines = "scan: complete\nreclaim|caches|1,5 GB|1.234\nreclaim|trash|512,5 MB|1,234\nfile|film.mov|2,25 GB|Videos|Movies\nfile|x.bin|1.024|Other|Desktop";
        $card = rcCard($lines);

        expect($card['summary'])->toBe('About 2 GB can usually be cleared.')
            ->and(rcItems($card, 'What you can clear')[0])->toMatchArray(['label' => 'App caches', 'value' => '1.5 GB'])
            ->and(rcItems($card, 'What you can clear')[0]['detail'])->toStartWith('1,234 items.')
            ->and(rcItems($card, 'What you can clear')[1])->toMatchArray(['label' => 'Trash', 'value' => '513 MB'])
            ->and(rcItems($card, 'Biggest files')[0])->toMatchArray(['label' => 'film.mov', 'value' => '2.3 GB'])
            ->and(rcItems($card, 'Biggest files')[1]['value'])->toBe('1 KB');
    });
});

describe('the biggest files', function () {
    it('keeps the ten biggest, biggest first', function () {
        $lines = "scan: complete\n".implode("\n", array_map(fn (int $i): string => "file|file{$i}.bin|".($i * 1024).'|Other|Docs', range(1, 14)));
        $labels = array_column(rcItems(rcCard($lines), 'Biggest files'), 'label');

        expect($labels)->toHaveCount(10)->and($labels[0])->toBe('file14.bin')->and($labels[9])->toBe('file5.bin');
    });

    it('cleans names: control characters, direction tricks, slashes and extra spaces go', function () {
        $name = "a\x07b\u{202E}c\x1b[31m  d/e\\f\t g";
        $card = rcCard("scan: complete\nfile|{$name}|2048|Other|Docs");

        expect($card['bars'][0]['items'][0]['label'])->toBe('abc[31m def g');
    });

    it('keeps accents and other scripts', function () {
        $card = rcCard("scan: complete\nfile|Caf\u{e9} menu \u{65e5}\u{672c}.pdf|2048|Documents|Documenten");

        expect($card['bars'][0]['items'][0]['label'])->toBe("Caf\u{e9} menu \u{65e5}\u{672c}.pdf");
    });

    it('caps a name at 80 characters without cutting a character in half', function () {
        $card = rcCard("scan: complete\nfile|".str_repeat("\u{e9}", 200)."|2048|Other|Docs\nfile|".str_repeat('x', 200).'|1024|Other|Docs');
        $labels = array_column($card['bars'][0]['items'], 'label');

        expect(mb_strlen($labels[0]))->toBe(80)->and(mb_check_encoding($labels[0], 'UTF-8'))->toBeTrue()
            ->and(mb_strlen($labels[1]))->toBe(80);
    });

    it('repairs bytes that are not valid text', function () {
        $card = rcCard("scan: complete\nfile|bad\xff\xfename.bin|2048|Other|Docs");

        expect(mb_check_encoding($card['bars'][0]['items'][0]['label'], 'UTF-8'))->toBeTrue();
    });

    it('drops a file whose name is empty after cleaning, or a dot name, or a size of zero', function (string $line) {
        expect(rcCard("scan: complete\n{$line}\nfile|ok.bin|2048|Other|Docs")['bars'][0]['items'])->toHaveCount(1);
    })->with(['control only' => ["file|\x07\x08|2048|Other|Docs"], 'dot' => ['file|.|2048|Other|Docs'], 'dotdot' => ['file|..|2048|Other|Docs'], 'zero' => ['file|zero.bin|0|Other|Docs'], 'a pipe splits it into extra fields' => ['file|a|b.bin|2048|Other|Docs']]);

    it('keeps the bigger one when the same name sits twice in the same folder', function () {
        $items = rcItems(rcCard("scan: complete\nfile|Movie.MKV|1024|Videos|Movies\nfile|movie.mkv|4096|Videos|movies\nfile|movie.mkv|2048|Videos|Other"), 'Biggest files');

        expect(array_column($items, 'value'))->toBe(['4 MB', '2 MB']);
    });

    it('says user folder when the file is not inside a named folder, and tidies the folder name', function () {
        $items = rcItems(rcCard("scan: complete\nfile|a.bin|4096|Other|\nfile|b.bin|2048|Other|\x07\x1b\nfile|c.bin|1024|Mystery|".str_repeat('F', 100)), 'Biggest files');

        expect($items[0]['detail'])->toBe('Other, in your user folder')
            ->and($items[1]['detail'])->toBe('Other, in your user folder')
            ->and($items[2]['detail'])->toBe('Other, in '.str_repeat('F', 60));
    });

    it('explains which files are never listed', function () {
        expect(rcCard(RC_LINES)['notes'])->toContain('Only files of 50 MB or more are listed. Hidden folders, apps, the Library and AppData folders and files that only exist in the cloud are left out.');
    });

    it('draws a bar that never reads zero for a small file next to a big one', function () {
        $items = rcItems(rcCard("scan: complete\nfile|big.bin|10485760|Other|A\nfile|small.bin|1|Other|A"), 'Biggest files');

        expect($items[1]['share'])->toBe(1);
    });
});
