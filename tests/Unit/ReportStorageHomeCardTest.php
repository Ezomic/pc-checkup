<?php

declare(strict_types=1);

use App\Services\Reports\ReportDetails;
use App\Services\Reports\ReportFacts;

const HC_DISK = '/dev/disk1s1  100Gi  80Gi  20Gi  80%  100  200  33%  /';

const HC_NAMES_NOTE = 'The names and sizes of the biggest folders in your user folder were recorded so this card can say what takes the space. Only you see them: they are not on the pages you share with clients.';

const HC_KINDS_NOTE = 'Kinds of files count your own user folder only. For them only sizes and counts were recorded, never file names.';

const HC_LINES = "scan: complete\nformat: 2\nroot|Users|52428800\nroots_total|52428800\nhome|52428800\n"
    ."home|Library|40894464\nhome|Documents|2097152\nhome|.cache|1048576\n"
    ."sub|Library|Application Support|31457280\nsub|Library|Caches|6291456\nsub|Library|Containers|1048576\n"
    ."sub|Documents|Taxes|1048576\n"
    ."type|Photos|1024|2\n";

function hcCard(string $lines, ?string $disk = HC_DISK): ?array
{
    $usage = $disk === null ? '' : "\n## Disk usage\n\n```\nFilesystem Size Used Avail Capacity iused ifree %iused Mounted on\n{$disk}\n```\n";
    $markdown = "# Mac checkup: Test\n\n- Format: 1\n{$usage}\n## Storage breakdown\n\n```\n{$lines}\n```\n";

    foreach ((new ReportDetails(new ReportFacts))->cards($markdown, [], 'laptop') as $card) {
        if ($card['id'] === 'storage-breakdown') {
            return $card;
        }
    }

    return null;
}

/**
 * @return array{title: string, items: list<array<string, mixed>>}|null
 */
function hcGroup(array $card, string $title): ?array
{
    foreach ($card['bars'] as $group) {
        if ($group['title'] === $title) {
            return $group;
        }
    }

    return null;
}

describe('the card for the biggest folders in the user folder', function () {
    it('puts the group after the drive and before the well-known folders', function () {
        expect(array_column(hcCard(HC_LINES)['bars'], 'title'))->toBe([
            'Biggest folders on the drive',
            'Biggest folders in your user folder',
            'Your folders',
            'Kinds of files in your user folder',
        ]);
    });

    it('draws a bar per folder with its size and share of the used space', function () {
        $items = hcGroup(hcCard(HC_LINES), 'Biggest folders in your user folder')['items'];

        expect(array_column($items, 'label'))->toBe(['Library', 'Documents', '.cache'])
            ->and(array_slice($items[0], 0, 4))->toBe(['label' => 'Library', 'value' => '39 GB', 'detail' => '49% of used space', 'share' => 100])
            ->and($items[1]['value'])->toBe('2 GB')
            ->and($items[1]['share'])->toBe(5);
    });

    it('lists the folders inside the two biggest under a label, each as a share of its parent', function () {
        $items = hcGroup(hcCard(HC_LINES), 'Biggest folders in your user folder')['items'];

        expect($items[0]['inside']['title'])->toBe('Inside Library')
            ->and($items[0]['inside']['items'])->toBe([
                ['label' => 'Application Support', 'value' => '30 GB', 'detail' => '77% of Library', 'share' => 100],
                ['label' => 'Caches', 'value' => '6 GB', 'detail' => '15% of Library', 'share' => 20],
                ['label' => 'Containers', 'value' => '1 GB', 'detail' => '3% of Library', 'share' => 3],
            ])
            ->and($items[1]['inside']['title'])->toBe('Inside Documents')
            ->and($items[2])->not->toHaveKey('inside');
    });

    it('shows a folder name as plain text', function () {
        $card = hcCard("scan: complete\nformat: 2\nhome|<script>alert(1)</script>|100\nhome|{{ x }}|50");

        expect(hcGroup($card, 'Biggest folders in your user folder')['items'][0]['label'])->toBe('<script>alert(1)<script>');
    });

    it('names the biggest folder in the summary when it is at least a fifth of the used space', function () {
        expect(hcCard(HC_LINES)['summary'])->toBe('Most of the space is used by Users (50 GB). Your user folder uses 50 GB; the biggest part is Library (39 GB). 30 GB could not be read.');
    });

    it('does not name a folder that is under a fifth of the used space', function () {
        $card = hcCard("scan: complete\nformat: 2\nroot|Users|52428800\nroots_total|52428800\nhome|52428800\nhome|Library|16777215\nhome|Documents|100");

        expect($card['summary'])->toBe('Most of the space is used by Users (50 GB). 30 GB could not be read.');
    });

    it('names a folder at exactly a fifth', function () {
        $card = hcCard("scan: complete\nformat: 2\nhome|52428800\nhome|Library|16777216");

        expect($card['summary'])->toBe('Your user folder uses 50 GB; the biggest part is Library (16 GB).');
    });

    it('names the biggest folder without a total when the total was not measured', function () {
        $card = hcCard("scan: partial\nformat: 2\nhome|Library|40894464");

        expect($card['summary'])->toBe('The biggest folder in your user folder is Library (39 GB).');
    });

    it('measures the fifth against the user folder when the used space is unknown', function () {
        $card = hcCard("scan: complete\nformat: 2\nhome|2048\nhome|Library|1024\nhome|Other|50", null);

        expect($card['summary'])->toBe('Your user folder uses 2 MB; the biggest part is Library (1 MB).');
    });

    it('does not name a folder when nothing is known to compare it with', function () {
        $card = hcCard("scan: complete\nformat: 2\nhome|Library|100", null);

        expect($card['summary'])->toBeNull();
    });

    it('says in plain words that the folder names were recorded, and keeps the kinds note honest', function () {
        $notes = hcCard(HC_LINES)['notes'];

        expect($notes)->toContain(HC_NAMES_NOTE)->toContain(HC_KINDS_NOTE)
            ->and(array_search(HC_NAMES_NOTE, $notes, true))->toBeLessThan(array_search(HC_KINDS_NOTE, $notes, true));
    });

    it('says a big folder may be missing when the scan stopped at its time limit', function () {
        $notes = hcCard(str_replace('scan: complete', 'scan: partial (time limit of 150 seconds reached)', HC_LINES))['notes'];

        expect($notes)->toContain('The scan stopped at its time limit, so these sizes are a minimum and the real numbers may be bigger.')
            ->toContain('The scan stopped at its time limit, so a big folder may be missing from the folders in your user folder.');
    });

    it('does not say the list may be missing a folder for a complete scan', function () {
        expect(implode(' ', hcCard(HC_LINES)['notes']))->not->toContain('may be missing');
    });

    it('draws what was measured when only some folders were', function () {
        $card = hcCard("scan: partial\nformat: 2\nhome|Library|2097152\nmissing|home/Downloads");
        $items = hcGroup($card, 'Biggest folders in your user folder')['items'];

        expect(array_column($items, 'label'))->toBe(['Library'])
            ->and($card['notes'])->toContain('Not measured before the time ran out: Downloads.');
    });

    it('leaves the notes about names out of a report from before they were recorded', function () {
        $notes = hcCard("scan: complete\nformat: 2\nhome|5000\nfolder|Downloads|100\ntype|Photos|1024|2")['notes'];

        expect($notes)->toBe(['Kinds of files count your own user folder only. Only sizes and counts were recorded, no file names.']);
    });

    it('does not have the group in a report from before they were recorded', function () {
        expect(hcGroup(hcCard("scan: complete\nformat: 2\nhome|5000\nfolder|Downloads|100"), 'Biggest folders in your user folder'))->toBeNull();
    });

    it('lets the storage card drop its own biggest folders list once the named list exists', function () {
        $markdown = "# Mac checkup: Test\n\n## Performance: biggest folders in home\n\n```\n38G\t/Users/me/Applications\n```\n\n## Storage breakdown\n\n```\nscan: partial\nformat: 2\nhome|Library|100\n```\n";
        $card = collect((new ReportDetails(new ReportFacts))->cards($markdown, [], 'laptop'))->firstWhere('id', 'storage');

        expect($card['list'])->toBeNull();
    });
});
