<?php

declare(strict_types=1);

use App\Services\Reports\ReportSections;
use App\Services\Reports\ReportUnusedApps;

const UA_HEAD = "scan: complete\nseconds: 12\nlimit: 45\nformat: 1\nscanned: 2026-11-06\n";

function uaCard(string $lines, string $heading = 'Apps you may not need'): ?array
{
    return (new ReportUnusedApps)->card(ReportSections::parse("# Checkup\n\n- Format: 1\n\n## {$heading}\n\n```\n{$lines}\n```\n"));
}

/**
 * @return list<array{label: string, value: string, detail: string|null, share: int}>
 */
function uaItems(?array $card, int $group = 0): array
{
    return $card['bars'][$group]['items'] ?? [];
}

describe('the card', function () {
    it('lists name, size and last used, biggest first, with a headline and the advice', function () {
        $card = uaCard(UA_HEAD."app|Old Editor|3145728|2023-05-01\napp|Big Suite|6291456|2024-10-01\napp|Spare Tool|1048576|2022-01-15");

        expect($card['id'])->toBe('unused-apps')
            ->and($card['title'])->toBe('Apps you may not need')
            ->and($card['summary'])->toBe('About 10 GB in apps not opened for a year')
            ->and($card['rows'])->toBe([])
            ->and($card['bars'][0]['title'])->toBe('Not opened for a year or more')
            ->and(uaItems($card))->toBe([
                ['label' => 'Big Suite', 'value' => '6 GB', 'detail' => 'Last opened 2024-10-01', 'share' => 100],
                ['label' => 'Old Editor', 'value' => '3 GB', 'detail' => 'Last opened 2023-05-01', 'share' => 50],
                ['label' => 'Spare Tool', 'value' => '1 GB', 'detail' => 'Last opened 2022-01-15', 'share' => 17],
            ])
            ->and($card['notes'][0])->toContain('Uninstall the ones you do not recognise or need')
            ->and($card['notes'][0])->not->toContain('delete')
            ->and(implode(' ', $card['notes']))->toContain('Only you see these app names');
    });

    it('lists the apps with no record of use separately and does not call them unopened in the headline', function () {
        $card = uaCard(UA_HEAD."app|Known|2097152|2024-01-01\napp|Mystery|4194304|unknown");

        expect($card['summary'])->toBe('About 2 GB in apps not opened for a year')
            ->and($card['bars'][1]['title'])->toBe('Installed over a year ago, never opened or last use unknown')
            ->and(uaItems($card, 1)[0])->toBe(['label' => 'Mystery', 'value' => '4 GB', 'detail' => 'Last use unknown', 'share' => 100])
            ->and(uaItems($card)[0]['share'])->toBe(50)
            ->and(implode(' ', $card['notes']))->toContain('cannot be sure');
    });

    it('words the headline carefully when every app has an unknown last use (Windows without admin)', function () {
        expect(uaCard(UA_HEAD.'app|Mystery|4194304|unknown')['summary'])->toBe('About 4 GB in apps installed over a year ago with no record of use');
    });

    it('says at least when the scan stopped early or more apps were left out', function () {
        $partial = uaCard("scan: partial (time limit of 45 seconds reached)\nscanned: 2026-11-06\napp|A|2097152|2024-01-01");
        $more = uaCard(UA_HEAD."app|A|2097152|2024-01-01\nmore|4");

        expect($partial['summary'])->toBe('At least about 2 GB in apps not opened for a year')
            ->and(implode(' ', $partial['notes']))->toContain('time limit')
            ->and($more['summary'])->toStartWith('At least about')
            ->and(implode(' ', $more['notes']))->toContain('4 more apps are not listed')
            ->and(implode(' ', uaCard(UA_HEAD."app|A|2097152|2024-01-01\nmore|1")['notes']))->toContain('1 more app is not listed');
    });

    it('treats a missing scan line as partial', function () {
        expect(uaCard("scanned: 2026-11-06\napp|A|2097152|2024-01-01")['summary'])->toStartWith('At least');
    });

    it('reads Dutch decimal commas in sizes', function () {
        $card = uaCard(UA_HEAD."app|Groot|1,5 GB|2023-01-01\napp|Klein|880,5 MB|2023-01-01\napp|Te klein|99,9 MB|2023-01-01");

        expect(array_column(uaItems($card), 'value'))->toBe(['1.5 GB', '881 MB']);
    });
});

describe('what is dropped', function () {
    it('drops an app opened inside the last 12 months (exactly 12 months ago still counts), even if the script listed it', function () {
        $card = uaCard(UA_HEAD."app|Used|2097152|2026-01-01\napp|Edge|2097152|2025-11-06\napp|Just old|2097152|2025-11-05\napp|Future|2097152|2027-01-01");

        expect(array_column(uaItems($card), 'label'))->toBe(['Edge', 'Just old']);
    });

    it('measures the year from the scan date, not from today', function () {
        $card = uaCard("scan: complete\nscanned: 2024-03-01\napp|Old report app|2097152|2023-03-01\napp|Newer|2097152|2023-03-02");

        expect(array_column(uaItems($card), 'label'))->toBe(['Old report app']);
    });

    it('drops apps under 100 MB, zero, negative and absurd sizes', function () {
        $card = uaCard(UA_HEAD."app|Tiny|1024|2022-01-01\napp|Edge|102400|2022-01-01\napp|Zero|0|2022-01-01\napp|Neg|-5000000|2022-01-01\napp|Absurd|99999999999999 TB|2022-01-01\napp|Words|lots|2022-01-01");

        expect(array_column(uaItems($card), 'label'))->toBe(['Edge']);
    });

    it('drops rows with a bad date, wrong shape or an unknown prefix', function () {
        $card = uaCard(UA_HEAD."app|A|2097152|2022-02-30\napp|B|2097152|never\napp|C|2097152\napp|D|2097152|2022-01-01|extra\nfile|E|2097152|2022-01-01\napp||2097152|2022-01-01\napp|F|2097152|2022-01-01");

        expect(array_column(uaItems($card), 'label'))->toBe(['F']);
    });

    it('keeps at most 15 rows', function () {
        $lines = implode("\n", array_map(fn (int $i): string => 'app|App '.str_pad((string) $i, 2, '0', STR_PAD_LEFT).'|'.(200000 + $i * 1000).'|2022-01-01', range(1, 20)));

        expect(uaItems(uaCard(UA_HEAD.$lines)))->toHaveCount(15)
            ->and(uaItems(uaCard(UA_HEAD.$lines))[0]['label'])->toBe('App 20');
    });

    it('shows one row for the same name in two places, the bigger one', function () {
        $card = uaCard(UA_HEAD."app|Same|2097152|2022-01-01\napp|SAME|4194304|2022-01-01\napp|same|1048576|2022-01-01");

        expect(uaItems($card))->toHaveCount(1)
            ->and(uaItems($card)[0]['value'])->toBe('4 GB')
            ->and(uaItems($card)[0]['label'])->toBe('SAME');
    });

    it('breaks ties between equal sizes by name', function () {
        expect(array_column(uaItems(uaCard(UA_HEAD."app|Beta|2097152|2022-01-01\napp|Alpha|2097152|2022-01-01")), 'label'))->toBe(['Alpha', 'Beta']);
    });
});

describe('names', function () {
    it('cleans control characters, slashes, pipes and markup characters from a name and limits it to 60', function () {
        $name = "Photo\u{0}Tool/\\ \u{202E}Pro\t2024".str_repeat('x', 100);
        $card = uaCard(UA_HEAD."app|{$name}|2097152|2022-01-01");
        $label = uaItems($card)[0]['label'];

        expect(mb_strlen($label))->toBeLessThanOrEqual(60)
            ->and($label)->toStartWith('PhotoTool Pro 2024')
            ->and($label)->not->toContain("\u{0}")->not->toContain('/')->not->toContain("\u{202E}");
    });

    it('keeps unicode names', function () {
        expect(uaItems(uaCard(UA_HEAD."app|Textverarbeitung \u{fc}ber|2097152|2022-01-01"))[0]['label'])->toBe("Textverarbeitung \u{fc}ber");
    });

    it('drops a name that is empty or only dots after cleaning', function () {
        expect(uaCard(UA_HEAD."app|..|2097152|2022-01-01\napp| \u{200B} |2097152|2022-01-01\napp|/|2097152|2022-01-01"))->toBeNull();
    });

    it('does not turn markup into anything but text', function () {
        expect(uaItems(uaCard(UA_HEAD.'app|<img src=x onerror=alert(1)>|2097152|2022-01-01'))[0]['label'])->toBe('<img src=x onerror=alert(1)>');
    });

    it('never includes a path or a bundle id, only what it was given as a name', function () {
        $encoded = (string) json_encode(uaCard(UA_HEAD.'app|Name|2097152|2022-01-01'));

        expect($encoded)->not->toContain('/Applications')->not->toContain('com.')->not->toContain('C:');
    });
});

describe('no card', function () {
    it('has none for an old report, a missing scan date, or no usable app', function (string $lines) {
        expect(uaCard($lines))->toBeNull();
    })->with([
        'no date' => ["scan: complete\napp|A|2097152|2022-01-01"],
        'bad date' => ["scanned: 06-11-2026\napp|A|2097152|2022-01-01"],
        'no rows' => [UA_HEAD],
        'empty' => [''],
    ]);

    it('has none for an old report without the section', function () {
        expect(uaCard(UA_HEAD.'app|A|2097152|2022-01-01', 'Installed apps'))->toBeNull();
    });

    it('survives a huge number in the more line', function () {
        expect(uaCard(UA_HEAD."app|A|2097152|2022-01-01\nmore|99999999999999999999")['summary'])->toStartWith('About')
            ->and(uaCard(UA_HEAD."app|A|2097152|2022-01-01\nmore|1,2,3")['summary'])->toStartWith('About');
    });
});
