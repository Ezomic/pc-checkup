<?php

declare(strict_types=1);

use App\Services\Reports\ReportDetails;
use App\Services\Reports\ReportParser;
use App\Services\Reports\ReportRedactor;
use App\Services\Reports\ReportUserMasker;

/** The real account names each shipped fixture holds; a fixture missing here holds none. */
const UMC_ACCOUNTS = [
    'mac-checkup-air.md' => ['testuser'],
    'upload-special-chars.md' => ['robbi'],
    'windows-checkup-eigenaar.md' => ['Eigenaar'],
];

function umcFixture(string $file): string
{
    return (string) file_get_contents(__DIR__.'/../Fixtures/'.$file);
}

/**
 * @return list<string>
 */
function umcFixtureFiles(): array
{
    return array_map('basename', glob(__DIR__.'/../Fixtures/*.md') ?: []);
}

/**
 * @return list<array<string, mixed>>
 */
function umcCards(string $markdown): array
{
    $parsed = (new ReportParser)->parse($markdown);

    return app(ReportDetails::class)->cards($markdown, $parsed->flags, $parsed->deviceType);
}

/**
 * The cards of the report as the scan wrote it, with each account name swapped for the placeholder.
 *
 * @param  list<string>  $accounts
 * @return list<array<string, mixed>>
 */
function umcCardsWithAccountsMasked(string $markdown, array $accounts): array
{
    $cards = umcCards($markdown);

    array_walk_recursive($cards, function (mixed &$value) use ($accounts): void {
        foreach ($accounts as $account) {
            $value = is_string($value)
                ? (string) preg_replace('~(?<![\p{L}\p{N}_])'.preg_quote($account, '~').'(?![\p{L}\p{N}_])~iu', ReportUserMasker::PLACEHOLDER, $value)
                : $value;
        }
    });

    return $cards;
}

describe('a Windows report from a Dutch PC whose account is Eigenaar', function () {
    it('keeps the padding Windows PowerShell 5.1 puts after a program name', function () {
        expect(umcFixture('windows-checkup-eigenaar.md'))->toMatch('~^127\.0\.0\.1 +7768 Spotify +$~m')
            ->toMatch('~^127\.0\.0\.1 +1122 Battle\.net +$~m');
    });

    it('masks the account name and nothing else, so the programs in the listening ports keep their names', function () {
        $fixture = umcFixture('windows-checkup-eigenaar.md');

        expect((new ReportRedactor)->redact($fixture))->toBe(str_replace('Eigenaar', ReportUserMasker::PLACEHOLDER, $fixture));
    });

    it('still names the startup programs and the crashing apps, with no item called user', function () {
        $cards = array_column(umcCards((new ReportRedactor)->redact(umcFixture('windows-checkup-eigenaar.md'))), null, 'id');

        expect(array_column($cards['startup']['list']['items'], 'label'))
            ->toBe(['Docker Desktop', 'OneDrive', 'Spotify', 'Discord', 'Battle.net', 'Logitech G HUB'])
            ->and(array_column($cards['crashing-apps']['rows'], 'label'))
            ->toBe(['Spotify.exe', 'Discord.exe', 'Battle.net.exe', 'explorer.exe']);
    });
});

describe('every shipped report', function () {
    it('is covered, Mac and Windows alike', function () {
        $titles = array_map(fn (string $file): string => strtok(umcFixture($file), "\n") ?: '', umcFixtureFiles());

        expect(array_filter($titles, fn (string $title): bool => str_starts_with($title, '# Mac checkup:')))->not->toBeEmpty()
            ->and(array_filter($titles, fn (string $title): bool => str_starts_with($title, '# Windows checkup:')))->not->toBeEmpty();
    });

    it('keeps every card after redaction, apart from where the account name appeared', function (string $file) {
        $original = umcFixture($file);
        $redacted = (new ReportRedactor)->redact($original);
        $accounts = UMC_ACCOUNTS[$file] ?? [];

        expect(umcCards($redacted))->toBe(umcCardsWithAccountsMasked($original, $accounts));

        foreach ($accounts as $account) {
            expect($original)->toContain($account)->and($redacted)->not->toContain($account);
        }
    })->with(fn (): array => umcFixtureFiles());
});

it('masks a Mac account in every place the Mac sections print it, and nothing else', function () {
    $masked = umcFixture('mac-checkup-old-privacy.md');
    $named = str_replace(ReportUserMasker::PLACEHOLDER, 'janssen', $masked);

    expect($named)->not->toContain(ReportUserMasker::PLACEHOLDER)
        ->and((new ReportRedactor)->redact($named))->toBe($masked);
});
