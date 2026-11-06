<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

final class ScriptTemp
{
    private const ORPHAN_WAIT_SECONDS = 5;

    /** @var list<string> */
    private static array $dirs = [];

    /** @var list<string> */
    private static array $bins = [];

    public static function dir(string $prefix): string
    {
        $dir = sys_get_temp_dir().'/'.$prefix.'-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($dir);
        self::$dirs[] = $dir;

        return $dir;
    }

    /**
     * Fake system commands that record their pid, so the test run can prove none outlives the script.
     *
     * @param  array<string, string>  $scripts  command name => script body
     */
    public static function bin(string $prefix, array $scripts): string
    {
        $dir = self::dir($prefix);
        self::$bins[] = $dir;

        foreach ($scripts as $name => $body) {
            file_put_contents($dir.'/'.$name, "#!/bin/bash\necho \$\$ >> \"\$(dirname \"\$0\")/.pids\"\n".$body."\n");
            chmod($dir.'/'.$name, 0755);
        }

        return $dir;
    }

    /**
     * @return list<int>
     */
    public static function orphans(): array
    {
        $deadline = microtime(true) + self::ORPHAN_WAIT_SECONDS;

        do {
            $alive = self::alive();

            if ($alive === []) {
                break;
            }

            usleep(100_000);
        } while (microtime(true) < $deadline);

        return $alive;
    }

    public static function cleanUp(): void
    {
        $orphans = self::orphans();

        foreach ($orphans as $pid) {
            Process::run(['kill', '-KILL', (string) $pid]);
        }

        foreach (self::$dirs as $dir) {
            File::deleteDirectory($dir);
        }

        self::$dirs = [];
        self::$bins = [];

        expect($orphans)->toBe([]);
    }

    /**
     * @return list<int>
     */
    private static function alive(): array
    {
        $alive = [];

        foreach (self::$bins as $bin) {
            $pids = is_file($bin.'/.pids') ? file($bin.'/.pids', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : false;

            if ($pids === false || $pids === []) {
                continue;
            }

            $listing = Process::run(['ps', '-o', 'pid=,stat=,args=', '-p', implode(',', array_unique($pids))])->output();

            foreach (explode(PHP_EOL, $listing) as $line) {
                if (preg_match('/^\s*(\d+)\s+(\S+)\s+(.*)$/', $line, $found) !== 1 || str_starts_with($found[2], 'Z')) {
                    continue;
                }

                if (str_contains($found[3], $bin) || str_starts_with($found[3], 'sleep ')) {
                    $alive[] = (int) $found[1];
                }
            }
        }

        return $alive;
    }
}
