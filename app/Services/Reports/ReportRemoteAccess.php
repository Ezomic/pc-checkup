<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Enums\AttentionLevel;

/**
 * @phpstan-import-type Card from ReportDetails
 * @phpstan-import-type Reason from \App\Services\NeedsAttention
 *
 * @phpstan-type Tool array{name: string, installed: bool, running: bool}
 */
final readonly class ReportRemoteAccess
{
    public const KNOWN = [
        'TeamViewer', 'AnyDesk', 'UltraViewer', 'RustDesk', 'Supremo', 'ScreenConnect', 'LogMeIn', 'Splashtop',
        'AeroAdmin', 'Ammyy Admin', 'Chrome Remote Desktop', 'Remote Utilities', 'Radmin', 'RealVNC', 'TightVNC',
        'UltraVNC', 'NoMachine', 'Zoho Assist', 'DWService', 'HopToDesk', 'GoTo Resolve', 'BeyondTrust', 'Parsec',
    ];

    public const OWN_TOOL = 'RustDesk';

    private const SECTION = 'Remote access tools';

    /**
     * Apple's own parsecd (Siri and Spotlight Suggestions) runs on every Mac, and older Mac scans
     * reported it as Parsec, so on a Mac only an installed Parsec counts.
     */
    private const MAC_LOOKALIKE = 'Parsec';

    /**
     * @return Card|null
     */
    public function card(ReportSections $sections): ?array
    {
        $read = $this->read($sections);

        if ($read === null) {
            return null;
        }

        [$tools, $partial] = $read;
        $count = count($tools);

        return [
            'id' => 'remote-access',
            'title' => 'Remote-access tools',
            'summary' => match ($count) {
                0 => 'None of the known remote-access tools were found.',
                1 => '1 remote-access tool found. Check that you recognise it.',
                default => "{$count} remote-access tools found. Check that you recognise them.",
            },
            'rows' => array_map(fn (array $tool): array => ['label' => $tool['name'], 'value' => $this->how($tool), 'tone' => 'neutral'], $tools),
            'notes' => array_values(array_filter([
                $count > 0 ? 'These let someone else see and control this computer. That is fine for support you know about. Check that you or the client set these up on purpose: callers pretending to be a bank or helpdesk often ask people to install one.' : null,
                $partial ? 'The scan stopped at its time limit, so a tool may be missing from this list.' : null,
                'Only a fixed list of known tools is looked for. No other apps are recorded.',
                'Only you see this: it is not on the pages you share with clients.',
            ])),
            'list' => null,
        ];
    }

    /**
     * @return list<Reason>
     */
    public function reasons(ReportSections $sections): array
    {
        $names = array_values(array_filter(array_column($this->read($sections)[0] ?? [], 'name'), fn (string $name): bool => $name !== self::OWN_TOOL));

        return $names === [] ? [] : [['level' => AttentionLevel::Soon->value, 'text' => 'Remote-access tools found ('.implode(', ', $names).'): check they are recognised']];
    }

    /**
     * @return array{0: list<Tool>, 1: bool}|null
     */
    private function read(ReportSections $sections): ?array
    {
        $text = $sections->text(self::SECTION);
        $lines = $text === null ? [] : (preg_split('/\r\n|\r|\n/', $text) ?: []);
        $scan = strtolower((string) ReportDates::keyValue($lines, 'scan'));
        $partial = str_starts_with($scan, 'partial');
        $mac = strtolower((string) $sections->header('os')) === 'macos';
        $tools = array_values(array_filter($this->tools($lines), fn (array $tool): bool => ! $mac || $tool['name'] !== self::MAC_LOOKALIKE || $tool['installed']));

        if (($scan !== 'complete' && ! $partial) || ($partial && $tools === [])) {
            return null;
        }

        return [$tools, $partial];
    }

    /**
     * @param  list<string>  $lines
     * @return list<Tool>
     */
    private function tools(array $lines): array
    {
        $known = array_combine(array_map('strtolower', self::KNOWN), self::KNOWN);
        $tools = [];

        foreach ($lines as $line) {
            $parts = explode('|', trim($line));
            $name = count($parts) === 3 && $parts[0] === 'tool' ? ($known[strtolower($parts[1])] ?? null) : null;
            $states = $name === null ? [] : array_map('trim', explode(',', strtolower($parts[2])));

            if ($name === null || array_diff($states, ['installed', 'running']) !== []) {
                continue;
            }

            $tools[$name] = [
                'name' => $name,
                'installed' => ($tools[$name]['installed'] ?? false) || in_array('installed', $states, true),
                'running' => ($tools[$name]['running'] ?? false) || in_array('running', $states, true),
            ];
        }

        return array_values($tools);
    }

    /**
     * @param  Tool  $tool
     */
    private function how(array $tool): string
    {
        return match (true) {
            $tool['installed'] && $tool['running'] => 'Installed and running',
            $tool['installed'] => 'Installed',
            default => 'Running, not installed (a downloaded copy)',
        };
    }
}
