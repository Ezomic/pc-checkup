<?php

declare(strict_types=1);

use App\Services\Reports\ReportSections;

it('reads the header values before the first section', function () {
    $sections = ReportSections::parse(macReportFixture());

    expect($sections->header('Model'))->toBe('MacBook Air (MacBookAir7,2)')
        ->and($sections->header('free disk'))->toBe('12%')
        ->and($sections->header('nothing here'))->toBeNull();
});

it('gives the text of a section without the code fence markers', function () {
    $text = ReportSections::parse(macReportFixture())->text('Storage (boot volume)');

    expect($text)->toContain('SMART Status:              Verified')
        ->and($text)->not->toContain('```');
});

it('finds a section whatever its letter case', function () {
    expect(ReportSections::parse(macReportFixture())->text('DISK USAGE'))->toContain('/dev/disk1s1');
});

it('returns null for a section the report does not have', function () {
    expect(ReportSections::parse(macReportFixture())->text('Battery and power'))->toBeNull();
});

it('returns an empty string for an empty code fence', function () {
    $sections = ReportSections::parse("# Report\n\n## Disk usage\n\n```\n```\n\n## Next\n\n```\nx\n```\n");

    expect($sections->text('Disk usage'))->toBe('')
        ->and($sections->text('Next'))->toBe('x');
});

it('does not start a section at a heading inside a code fence', function () {
    $sections = ReportSections::parse("# Report\n\n## First\n\n```\n## Not a heading\nreal line\n```\n\n## Second\n\ntext\n");

    expect($sections->text('Not a heading'))->toBeNull()
        ->and($sections->text('First'))->toContain('## Not a heading')->toContain('real line')
        ->and($sections->text('Second'))->toBe('text');
});

it('keeps the first of two sections with the same heading', function () {
    $sections = ReportSections::parse("# R\n\n## Twin\n\nfirst\n\n## Twin\n\nsecond\n");

    expect($sections->text('Twin'))->toBe('first');
});

it('reads a report with Windows line endings', function () {
    $sections = ReportSections::parse(str_replace("\n", "\r\n", macReportFixture()));

    expect($sections->header('model'))->toBe('MacBook Air (MacBookAir7,2)')
        ->and($sections->text('Disk usage'))->toContain('/dev/disk1s1')
        ->and($sections->text('Disk usage'))->not->toContain("\r");
});

it('treats an unterminated code fence as running to the end of the report', function () {
    $sections = ReportSections::parse("# R\n\n## Open\n\n```\nline\n## Inside\n");

    expect($sections->text('Open'))->toContain('## Inside')->and($sections->text('Inside'))->toBeNull();
});

it('does not take header values from a code fence', function () {
    $sections = ReportSections::parse("# R\n\n```\n- Model: Fake\n```\n- Memory: 8 GB\n");

    expect($sections->header('model'))->toBeNull()->and($sections->header('memory'))->toBe('8 GB');
});

it('handles a very long section', function () {
    $body = implode("\n", array_fill(0, 50000, 'filler line'));
    $sections = ReportSections::parse("# R\n\n## Big\n\n```\n{$body}\n```\n\n## After\n\nok\n");

    expect(substr_count((string) $sections->text('Big'), "\n"))->toBe(49999)
        ->and($sections->text('After'))->toBe('ok');
});

it('copes with an empty report', function () {
    $sections = ReportSections::parse('');

    expect($sections->header('model'))->toBeNull()->and($sections->text('anything'))->toBeNull();
});
