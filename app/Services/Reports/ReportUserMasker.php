<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Enums\CheckupScript;

/**
 * Both scans print some sections under the same title in different formats (the listening ports
 * are lsof on a Mac and a process table on Windows), so a rule that reads a section only runs on
 * the platform whose scan printed it.
 */
final class ReportUserMasker
{
    public const PLACEHOLDER = '<user>';

    private const MAX_NAME_LENGTH = 64;

    private const MIN_GLOBAL_LENGTH = 5;

    private const MAX_GLOBAL_NAMES = 50;

    private const MIN_CRASH_LENGTH = 3;

    private const CRASH_SECTION = '~^## Recent app crashes\n.*?(?=^## |\z)~ms';

    private const SYSTEM_NAMES = [
        'root', 'daemon', 'nobody', 'wheel', 'shared', 'guest', 'public', 'default', 'default user', 'all users',
        'defaultuser0', 'administrator', 'defaultaccount', 'wdagutilityaccount', 'system', 'localservice', 'networkservice',
        'reboot', 'shutdown', 'wtmp', 'gast',
    ];

    private const COMMON_WORDS = [
        'admin', 'user', 'users', 'test', 'apple', 'mac', 'macbook', 'windows', 'desktop', 'laptop', 'computer', 'owner',
        'home', 'data', 'library', 'temp', 'family', 'office', 'work', 'login', 'console', 'name', 'none', 'true', 'false',
        'dell', 'lenovo', 'microsoft', 'google', 'chrome', 'safari', 'firefox', 'steam', 'brave', 'adobe', 'intel', 'nvidia',
        'asus', 'acer', 'razer', 'samsung', 'imac', 'macos',
    ];

    private const MAC_PATH = '~(/Users/)([^\s/:"\'<>|,;()\\\\]+)~u';

    private const WINDOWS_PATH = '~([A-Za-z]:[\\\\/]Users[\\\\/])(?:([^\\\\/:*?"<>|\r\n]+)(?=[\\\\/])|([^\s\\\\/:*?"<>|,;\'()]+))~iu';

    private const SESSION_LINE = '~^(\S+)(\s+(?:console|ttys?\d+|tty\S*|pts/\d+|:\d+)\b.*)$~s';

    private const LISTENER_LINE = '~^(\S+\s+\d+\s+)(\S+)(\s.*)$~s';

    private const NAME_CHARACTERS = '~\A[^<>$%*{}\[\]?\\\\/\x00-\x1f\x7f]+\z~u';

    private const TITLE = '~\A# (Windows|Mac) checkup:~';

    private const OS_LINE = '~^- OS:(.*)$~m';

    public function mask(string $markdown): string
    {
        $lines = explode("\n", $markdown);
        $platform = $this->platform($markdown);
        $names = [];
        $section = '';
        $state = ['mode' => 'none', 'console' => false];

        foreach ($lines as $index => $line) {
            if (str_starts_with($line, '## ')) {
                $section = mb_strtolower(trim(substr($line, 3)));
            }

            if (str_starts_with($line, '```')) {
                $state = ['mode' => 'list', 'console' => false];
            }

            $lines[$index] = $this->maskLine($platform, $section, $line, $state, $names);
        }

        return $this->maskCrashNames($this->maskElsewhere(implode("\n", $lines), $names), $names);
    }

    /**
     * A report that names its platform nowhere gets only the home folder rules, which cannot be
     * mistaken for anything else.
     */
    private function platform(string $markdown): ?CheckupScript
    {
        $head = explode("\n## ", $markdown, 2)[0];

        if (preg_match(self::TITLE, $head, $match) === 1) {
            return $match[1] === 'Windows' ? CheckupScript::Windows : CheckupScript::Mac;
        }

        return preg_match(self::OS_LINE, $head, $match) === 1 ? CheckupScript::forOs($match[1]) : null;
    }

    /**
     * App names in the crash list come from log file names and can carry a user name of any length.
     *
     * @param  array<string, string>  $names
     */
    private function maskCrashNames(string $markdown, array $names): string
    {
        $words = array_filter(
            $names,
            fn (string $name, string $key): bool => mb_strlen($key) >= self::MIN_CRASH_LENGTH && ! in_array($key, self::COMMON_WORDS, true),
            ARRAY_FILTER_USE_BOTH,
        );

        if ($words === [] || preg_match(self::CRASH_SECTION, $markdown, $section, PREG_OFFSET_CAPTURE) !== 1) {
            return $markdown;
        }

        $words = array_slice(array_values($words), 0, self::MAX_GLOBAL_NAMES);
        usort($words, fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
        $pattern = '~(?<![\p{L}\p{N}_])(?:'.implode('|', array_map(fn (string $word): string => preg_quote($word, '~'), $words)).')(?![\p{L}\p{N}_])~iu';
        $masked = preg_replace_callback('~^(app\|)([^|\n]*)~mu', fn (array $m): string => $m[1].(preg_replace($pattern, self::PLACEHOLDER, $m[2]) ?? self::PLACEHOLDER), mb_scrub($section[0][0])) ?? '';

        return substr_replace($markdown, $masked, (int) $section[0][1], strlen($section[0][0]));
    }

    /**
     * @param  array{mode: string, console: bool}  $state
     * @param  array<string, string>  $names
     */
    private function maskLine(?CheckupScript $platform, string $section, string $line, array &$state, array &$names): string
    {
        if (str_starts_with($line, '```')) {
            return $line;
        }

        if ($platform !== CheckupScript::Windows) {
            $line = $this->macPath($line, $names);
        }

        if ($platform !== CheckupScript::Mac) {
            $line = $this->windowsPath($line, $names);
        }

        return match ($platform) {
            CheckupScript::Mac => $this->macSectionLine($section, $line, $state, $names),
            CheckupScript::Windows => str_starts_with($section, 'user accounts') ? $this->windowsAccountLine($line, $state, $names) : $line,
            null => $line,
        };
    }

    /**
     * @param  array{mode: string, console: bool}  $state
     * @param  array<string, string>  $names
     */
    private function macSectionLine(string $section, string $line, array &$state, array &$names): string
    {
        return match (true) {
            str_starts_with($section, 'user accounts') => $this->macAccountLine($line, $state, $names),
            str_starts_with($section, 'logged in users') => $this->sessionLine($line, $names),
            str_starts_with($section, 'listening network ports') => $this->listenerLine($line, $names),
            str_starts_with($section, 'cron jobs') => $this->crontabLine($line, $names),
            default => $line,
        };
    }

    /**
     * @param  array<string, string>  $names
     */
    private function macPath(string $line, array &$names): string
    {
        return preg_replace_callback(self::MAC_PATH, function (array $m) use (&$names): string {
            return $m[1].$this->name($m[2], $names);
        }, $line) ?? $line;
    }

    /**
     * @param  array<string, string>  $names
     */
    private function windowsPath(string $line, array &$names): string
    {
        return preg_replace_callback(self::WINDOWS_PATH, function (array $m) use (&$names): string {
            $quoted = $m[2] ?? '';
            $name = $quoted !== '' ? $quoted : ($m[3] ?? '');

            return $m[1].$this->name($name, $names);
        }, $line) ?? $line;
    }

    /**
     * @param  array<string, string>  $names
     */
    private function crontabLine(string $line, array &$names): string
    {
        return preg_replace_callback('~(\bno crontab for )(\S+)~i', function (array $m) use (&$names): string {
            return $m[1].$this->name($m[2], $names);
        }, $line) ?? $line;
    }

    /**
     * @param  array{mode: string, console: bool}  $state
     * @param  array<string, string>  $names
     */
    private function macAccountLine(string $line, array &$state, array &$names): string
    {
        $trimmed = trim($line);

        if ($state['console'] && $trimmed !== '') {
            $state['console'] = false;

            return $this->name($trimmed, $names);
        }

        if (preg_match('~^\s*Console user:\s*$~', $line) === 1) {
            $state['console'] = true;

            return $line;
        }

        if (preg_match('~^(\s*GroupMembership:\s*)(.*)$~', $line, $match) === 1) {
            $members = preg_split('~\s+~', trim($match[2]), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $masked = [];

            foreach ($members as $member) {
                $masked[] = $this->name($member, $names);
            }

            return $match[1].implode(' ', $masked);
        }

        if ($trimmed === '' || str_ends_with($trimmed, ':')) {
            $state['mode'] = 'none';
        }

        return $state['mode'] === 'list' && preg_match('~^\S+$~', $trimmed) === 1 ? $this->name($trimmed, $names) : $line;
    }

    /**
     * @param  array{mode: string, console: bool}  $state
     * @param  array<string, string>  $names
     */
    private function windowsAccountLine(string $line, array &$state, array &$names): string
    {
        $state['mode'] = match (true) {
            preg_match('~^\s*Name\s+Enabled\b~', $line) === 1 => 'header-users',
            preg_match('~^\s*Name\s+ObjectClass\b~', $line) === 1 => 'header-group',
            $state['mode'] === 'header-users' => 'users',
            $state['mode'] === 'header-group' => 'group',
            str_ends_with(trim($line), ':') => 'none',
            default => $state['mode'],
        };

        return match ($state['mode']) {
            'users' => $this->tableName($line, $names, false),
            'group' => $this->tableName($line, $names, true),
            default => $line,
        };
    }

    /**
     * @param  array<string, string>  $names
     */
    private function tableName(string $line, array &$names, bool $isGroup): string
    {
        $next = $isGroup ? '\s+User\s*' : '\s+(?:True|False)\b.*';

        if (preg_match('~^(\s*)(\S.*?)('.$next.')$~s', $line, $match) !== 1) {
            return $line;
        }

        $cut = $isGroup ? strrpos($match[2], '\\') : false;
        $prefix = $cut === false ? '' : substr($match[2], 0, $cut + 1);
        $name = $cut === false ? $match[2] : substr($match[2], $cut + 1);

        return $match[1].$prefix.$this->name($name, $names).$match[3];
    }

    /**
     * @param  array<string, string>  $names
     */
    private function sessionLine(string $line, array &$names): string
    {
        if (preg_match(self::SESSION_LINE, $line, $match) !== 1) {
            return $line;
        }

        return $this->name($match[1], $names).$match[2];
    }

    /**
     * @param  array<string, string>  $names
     */
    private function listenerLine(string $line, array &$names): string
    {
        if (preg_match(self::LISTENER_LINE, $line, $match) !== 1) {
            return $line;
        }

        return $match[1].$this->name($match[2], $names).$match[3];
    }

    /**
     * @param  array<string, string>  $names
     */
    private function name(string $name, array &$names): string
    {
        $key = mb_strtolower($name);

        if (
            $name !== trim($name)
            || mb_strlen($name) > self::MAX_NAME_LENGTH
            || str_starts_with($name, '_')
            || in_array($key, self::SYSTEM_NAMES, true)
            || preg_match(self::NAME_CHARACTERS, $name) !== 1
        ) {
            return $name;
        }

        $names[$key] = $name;

        return self::PLACEHOLDER;
    }

    /**
     * @param  array<string, string>  $names
     */
    private function maskElsewhere(string $markdown, array $names): string
    {
        $words = array_filter(
            $names,
            fn (string $name, string $key): bool => mb_strlen($key) >= self::MIN_GLOBAL_LENGTH && ! in_array($key, self::COMMON_WORDS, true),
            ARRAY_FILTER_USE_BOTH,
        );

        if ($words === []) {
            return $markdown;
        }

        $words = array_slice(array_values($words), 0, self::MAX_GLOBAL_NAMES);
        usort($words, fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
        $pattern = '~(?<![\p{L}\p{N}_.])(?:'.implode('|', array_map(fn (string $word): string => preg_quote($word, '~'), $words)).')(?![\p{L}\p{N}_]|\.\p{L})~iu';

        $split = strpos($markdown, "\n## ");
        $head = $split === false ? $markdown : substr($markdown, 0, $split);
        $body = $split === false ? '' : substr($markdown, $split);

        return $head.(preg_replace($pattern, self::PLACEHOLDER, $body) ?? $body);
    }
}
