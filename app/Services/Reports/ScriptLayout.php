<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Enums\CheckupScript;

/**
 * What a checkup script writes, read from the script itself so it never drifts from it. A section
 * counts only when its call starts a line: the scripts indent every call inside a block or a
 * function, so an indented call, or one after `if` or `&&`, may not run on every machine. A title
 * the shell or PowerShell would expand is left out, since its real text is not known here.
 */
final readonly class ScriptLayout
{
    private const string MAC_SECTION = '/^(?:sh_)?section[ \t]+(?|"([^"$`\\\\\n]+)"|\'([^\'\n]+)\')/m';

    private const string WINDOWS_SECTION = '/^Add-Section[ \t]+\'((?:[^\'\n]|\'\')+)\'/mi';

    private const string HEADING = '/^## +(\S.*?)[ \t]*$/m';

    private const string FORMAT = '/\bformat: (\d{1,3})\b/';

    /**
     * @param  list<string>  $sections  The titles of the sections every run writes, in order.
     * @param  string|null  $end  The last heading the script writes, which only a complete report has.
     */
    public function __construct(
        public string $version,
        public array $sections,
        public ?string $end,
        public int $newestFormat,
    ) {}

    public static function of(CheckupScript $script): self
    {
        return self::read($script, $script->contents(), $script->version());
    }

    public static function read(CheckupScript $script, string $source, string $version): self
    {
        $source = str_replace("\r\n", "\n", $source);
        preg_match_all($script === CheckupScript::Mac ? self::MAC_SECTION : self::WINDOWS_SECTION, $source, $calls);
        preg_match_all(self::HEADING, $source, $headings);
        preg_match_all(self::FORMAT, $source, $formats);

        $titles = $script === CheckupScript::Mac ? $calls[1] : array_map(fn (string $title): string => str_replace("''", "'", $title), $calls[1]);

        return new self(
            version: $version,
            sections: array_values(array_unique($titles)),
            end: $headings[1] === [] ? null : end($headings[1]),
            newestFormat: max([1, ...array_map(intval(...), $formats[1])]),
        );
    }
}
