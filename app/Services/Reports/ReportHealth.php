<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Enums\CheckupScript;
use Illuminate\Support\Str;

/**
 * Says in plain words what looks wrong with a stored report. Only a report from the current script
 * is held to the list of sections it writes, because an older script wrote fewer and that is no
 * fault; the end of the report is only looked for when a script version says which script wrote it,
 * because a report without one may be an old scan or a part someone pasted.
 */
final class ReportHealth
{
    public const int NAMED_SECTIONS = 5;

    private const int TITLE_LIMIT = 80;

    private const int VALUE_LIMIT = 20;

    private const string FORMAT_LINE = '/^format:(.*)$/m';

    private const string KNOWN_FORMAT = '/\A\d{1,3}\z/';

    /** @var array<string, ScriptLayout> */
    private array $layouts = [];

    /**
     * @param  list<string>  $failedCards  The titles of the cards that threw while they were built.
     * @return list<string>
     */
    public function problems(string $markdown, array $failedCards = []): array
    {
        $parsed = (new ReportParser)->parse($markdown);
        $script = CheckupScript::forOs($parsed->os);

        return [
            ...($script === null ? [] : $this->structure($this->layout($script), ReportSections::parse($markdown), $parsed->scriptVersion)),
            ...array_map(fn (string $card): string => "The {$card} card could not be read from this report, so it is left out. The error was logged.", $failedCards),
        ];
    }

    private function layout(CheckupScript $script): ScriptLayout
    {
        return $this->layouts[$script->value] ??= ScriptLayout::of($script);
    }

    /**
     * @return list<string>
     */
    private function structure(ScriptLayout $layout, ReportSections $sections, ?string $version): array
    {
        $cut = $version !== null && $layout->end !== null && $sections->text($layout->end) === null;

        return array_values(array_filter([
            $cut ? 'The report stops early: its last part is missing, so the scan or the upload was probably cut off.' : null,
            $version === $layout->version ? $this->missing($layout, $sections) : null,
            ...$this->formats($layout, $sections),
        ]));
    }

    private function missing(ScriptLayout $layout, ReportSections $sections): ?string
    {
        $missing = array_values(array_filter([ReportParser::FLAGS_TITLE, ...$layout->sections], fn (string $title): bool => $sections->text($title) === null));

        if ($missing === []) {
            return null;
        }

        $more = count($missing) - self::NAMED_SECTIONS;

        return 'These sections are missing, although this version of the script always writes them: '
            .implode(', ', array_slice($missing, 0, self::NAMED_SECTIONS)).($more > 0 ? " and {$more} more" : '').'.';
    }

    /**
     * @return list<string>
     */
    private function formats(ScriptLayout $layout, ReportSections $sections): array
    {
        $problems = [];

        foreach ($sections->titles() as $title) {
            if (preg_match(self::FORMAT_LINE, (string) $sections->text($title), $match) !== 1) {
                continue;
            }

            $value = trim($match[1]);
            $known = preg_match(self::KNOWN_FORMAT, $value) === 1 && (int) $value >= 1 && (int) $value <= $layout->newestFormat;

            if (! $known) {
                $problems[] = 'The '.Str::limit($this->printable($title), self::TITLE_LIMIT).' section is in a format this app does not know ('.$this->shown($value).'), so what the page shows about it may be wrong.';
            }
        }

        return $problems;
    }

    private function shown(string $value): string
    {
        $value = $this->printable($value);

        return $value === '' ? 'no number' : 'format: '.Str::limit($value, self::VALUE_LIMIT);
    }

    // Control, direction and zero-width characters from the report would garble or disguise the banner text.
    private function printable(string $text): string
    {
        return (string) preg_replace('/\p{C}+/u', '', $text);
    }
}
