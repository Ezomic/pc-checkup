<?php

declare(strict_types=1);

namespace App\Services\Reports;

/**
 * @phpstan-import-type Card from ReportDetails
 * @phpstan-import-type Row from ReportDetails
 */
final readonly class ReportDeviceProblems
{
    public const ROW_LIMIT = 10;

    private const TOTAL = '/^\d{1,4}$/';

    private const NOT_PROBLEMS = [0, 22, 24, 45];

    private const DRIVER = 'Update or reinstall its driver.';

    private const MEANINGS = [
        1 => 'It is not set up correctly. '.self::DRIVER,
        3 => 'Its driver may be damaged, or the computer is low on memory. '.self::DRIVER,
        10 => 'It cannot start. '.self::DRIVER,
        12 => 'It cannot get the resources it needs. Turn off another device or ask a technician.',
        14 => 'It needs a restart of the computer to work.',
        18 => 'Its driver needs to be reinstalled.',
        19 => 'Its settings in Windows are incomplete or damaged. '.self::DRIVER,
        21 => 'Windows is removing it.',
        28 => 'No driver is installed. Install the driver from the maker of the computer or device.',
        29 => 'The firmware did not give it the resources it needs. Check the BIOS settings.',
        31 => 'It is not working because Windows cannot load its driver. '.self::DRIVER,
        32 => 'Its driver is turned off. '.self::DRIVER,
        33 => 'Windows cannot tell what it needs. Ask a technician.',
        34 => 'Windows cannot set it up by itself. Ask a technician.',
        35 => 'The firmware lacks information to set it up. Update the BIOS.',
        37 => 'Its driver failed to start. '.self::DRIVER,
        38 => 'An older copy of its driver is still loaded. Restart the computer.',
        39 => 'Its driver is damaged or missing. '.self::DRIVER,
        40 => 'Its driver settings are missing. '.self::DRIVER,
        41 => 'Its driver loaded but the hardware was not found. '.self::DRIVER,
        42 => 'A duplicate of it was found. Restart the computer.',
        43 => 'Windows stopped it because it reported problems. '.self::DRIVER,
        44 => 'A program or service turned it off. Restart the computer.',
        47 => 'It was prepared for safe removal. Unplug it and plug it back in.',
        48 => 'Its driver is blocked because it is known to cause problems. Get a newer driver.',
        49 => 'Windows cannot start new devices because the device settings are too large. Remove unused devices.',
        50 => 'Windows could not apply all of its settings. Restart the computer.',
        51 => 'It is waiting for another device to start.',
        52 => 'Windows cannot check the signature of its driver. Get a signed driver.',
        53 => 'It is reserved for the Windows kernel debugger.',
        54 => 'It failed and is being reset.',
        56 => 'Windows is still setting it up. Restart the computer.',
    ];

    /**
     * @return Card|null
     */
    public function card(ReportSections $sections): ?array
    {
        $text = $sections->text('Devices with problems (Device Manager)');
        $lines = $text === null ? [] : (preg_split('/\r\n|\r|\n/', $text) ?: []);

        if (strtolower((string) ReportDates::keyValue($lines, 'scan')) !== 'complete') {
            return null;
        }

        $rows = $this->rows($lines);
        $counted = (string) ReportDates::keyValue($lines, 'total');
        $total = max(count($rows), preg_match(self::TOTAL, $counted) === 1 ? (int) $counted : 0);

        return [
            'id' => 'device-problems',
            'title' => 'Devices with problems',
            'summary' => match ($total) {
                0 => 'No device problems found in Device Manager',
                1 => '1 device reports a problem in Device Manager',
                default => number_format($total).' devices report a problem in Device Manager',
            },
            'rows' => array_slice($rows, 0, self::ROW_LIMIT),
            'list' => null,
            'notes' => array_values(array_filter([
                $total > self::ROW_LIMIT ? 'Only the first ten are listed.' : null,
                'Device names and problem codes only, from Device Manager.',
                'Only you see this: it is not on the pages you share with clients.',
            ])),
        ];
    }

    /**
     * @param  list<string>  $lines
     * @return list<Row>
     */
    private function rows(array $lines): array
    {
        $rows = [];

        foreach ($lines as $line) {
            $parts = explode('|', trim($line));
            $name = count($parts) === 3 && $parts[0] === 'device' ? ReportAppName::clean($parts[1]) : null;
            $code = $name === null || preg_match('/^\d{1,2}$/', $parts[2]) !== 1 ? null : (int) $parts[2];

            if ($name === null || $code === null || in_array($code, self::NOT_PROBLEMS, true)) {
                continue;
            }

            $rows[] = ['label' => $name, 'value' => self::MEANINGS[$code] ?? "Problem code {$code} in Device Manager", 'tone' => 'bad'];
        }

        return $rows;
    }
}
