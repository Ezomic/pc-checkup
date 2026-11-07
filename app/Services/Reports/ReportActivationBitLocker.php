<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Enums\AttentionLevel;

/**
 * @phpstan-import-type Card from ReportDetails
 * @phpstan-import-type Row from ReportDetails
 * @phpstan-import-type Reason from \App\Services\NeedsAttention
 *
 * @phpstan-type Read array{activation: string|null, drives: array<string, string>}
 */
final readonly class ReportActivationBitLocker
{
    public const RECOVERY_KEY_REMINDER = 'Make sure it is saved: in the Microsoft account (account.microsoft.com/devices/recoverykey) or printed and kept somewhere safe. Without it the files cannot be opened when Windows asks for it, for example after a BIOS update or a repair.';

    private const SECTION = 'Activation and BitLocker';

    private const ACTIVATE = 'Activate it in Settings > System > Activation';

    private const ACTIVATION = [
        'licensed' => ['Activated', 'good'],
        'notification' => ['Not activated. Windows shows reminders and locks some personal settings. '.self::ACTIVATE.'.', 'bad'],
        'unlicensed' => ['Not activated. '.self::ACTIVATE.'.', 'bad'],
        'grace period' => ['Not activated yet, it runs on a grace period. '.self::ACTIVATE.' before the period ends.', 'bad'],
        'non-genuine grace period' => ['Not activated: Windows reports a licence that is not genuine. Activate it with a genuine licence in Settings > System > Activation.', 'bad'],
    ];

    private const DRIVES = [
        'on' => ['BitLocker on', 'good'],
        'locked' => ['BitLocker on (locked)', 'good'],
        'off' => ['BitLocker off', 'neutral'],
        'encrypting' => ['BitLocker is being turned on (encrypting)', 'neutral'],
        'decrypting' => ['BitLocker is being turned off (decrypting)', 'neutral'],
        'suspended' => ['BitLocker suspended: the drive is encrypted but not protected. Resume protection in Control Panel > BitLocker Drive Encryption.', 'bad'],
        'waiting' => ['Encrypted, but BitLocker is waiting to be turned on, so it is not protected yet. Turn on BitLocker or device encryption to finish.', 'neutral'],
    ];

    private const WITH_RECOVERY_KEY = ['on', 'locked', 'suspended', 'encrypting'];

    /**
     * @return Card|null
     */
    public function card(ReportSections $sections): ?array
    {
        $read = $this->read($sections);

        if ($read === null || ($read['activation'] === null && $read['drives'] === [])) {
            return null;
        }

        $keyed = array_intersect($read['drives'], self::WITH_RECOVERY_KEY) !== [];

        return [
            'id' => 'activation-bitlocker',
            'title' => 'Activation and BitLocker',
            'rows' => array_values(array_filter([
                $read['activation'] === null ? null : $this->row('Windows activation', self::ACTIVATION[$read['activation']]),
                ...array_map(fn (string $letter, string $state): array => $this->row("Drive {$letter}", self::DRIVES[$state]), array_keys($read['drives']), $read['drives']),
                $keyed ? ['label' => 'Recovery key', 'value' => self::RECOVERY_KEY_REMINDER, 'tone' => 'neutral'] : null,
            ])),
            'list' => null,
            'notes' => [
                'Only whether Windows is activated was read, never the product key. BitLocker recovery keys are never read.',
                'Only you see this: it is not on the pages you share with clients.',
            ],
        ];
    }

    /**
     * @return list<Reason>
     */
    public function reasons(ReportSections $sections): array
    {
        $read = $this->read($sections);
        $suspended = array_keys($read['drives'] ?? [], 'suspended', true);

        return array_values(array_filter([
            $read !== null && $read['activation'] !== null && $read['activation'] !== 'licensed' ? ['level' => AttentionLevel::Soon->value, 'text' => 'Windows is not activated'] : null,
            $suspended === [] ? null : ['level' => AttentionLevel::Soon->value, 'text' => 'BitLocker is suspended on '.$this->join($suspended)],
        ]));
    }

    /**
     * @return Read|null
     */
    private function read(ReportSections $sections): ?array
    {
        $text = $sections->text(self::SECTION);

        if ($text === null) {
            return null;
        }

        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        $activation = strtolower((string) ReportDates::keyValue($lines, 'activation'));
        $complete = strtolower((string) ReportDates::keyValue($lines, 'bitlocker')) === 'complete';

        return [
            'activation' => array_key_exists($activation, self::ACTIVATION) ? $activation : null,
            'drives' => $complete ? $this->drives($lines) : [],
        ];
    }

    /**
     * @param  list<string>  $lines
     * @return array<string, string>
     */
    private function drives(array $lines): array
    {
        $drives = [];

        foreach ($lines as $line) {
            if (preg_match('/^drive\|([A-Za-z]):\|([a-z ]+)$/i', trim($line), $match) !== 1) {
                continue;
            }

            $letter = strtoupper($match[1]).':';
            $state = strtolower($match[2]);

            if (! isset($drives[$letter]) && array_key_exists($state, self::DRIVES)) {
                $drives[$letter] = $state;
            }
        }

        return $drives;
    }

    /**
     * @param  array{0: string, 1: 'good'|'bad'|'neutral'}  $words
     * @return Row
     */
    private function row(string $label, array $words): array
    {
        return ['label' => $label, 'value' => $words[0], 'tone' => $words[1]];
    }

    /**
     * @param  list<string>  $names
     */
    private function join(array $names): string
    {
        $last = array_pop($names);

        return $names === [] ? (string) $last : implode(', ', $names).' and '.$last;
    }
}
