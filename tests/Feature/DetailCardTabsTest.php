<?php

declare(strict_types=1);

use App\Services\Flags\FlagChecks;
use App\Services\Reports\DetailCardTabs;

/**
 * @param  list<'good'|'bad'|'neutral'>  $tones
 * @return array{id: string, title: string, rows: list<array{label: string, value: string, tone: 'good'|'bad'|'neutral'}>, list: null}
 */
function dctCard(string $id, array $tones = ['neutral']): array
{
    return [
        'id' => $id,
        'title' => ucfirst($id),
        'rows' => array_map(fn (string $tone, int $index): array => ['label' => "Row {$index}", 'value' => 'Value', 'tone' => $tone], $tones, array_keys($tones)),
        'list' => null,
    ];
}

/**
 * @param  list<array<string, mixed>>  $cards
 * @param  list<string>  $flags
 * @return array{verdict: array<string, mixed>|null, cards: list<array<string, mixed>>}
 */
function dctArrange(array $cards, array $flags = []): array
{
    return app(DetailCardTabs::class)->arrange($cards, $flags);
}

/**
 * @param  list<string>  $flags
 * @return array<string, string>
 */
function dctStatuses(array $cards, array $flags = []): array
{
    return array_column(dctArrange($cards, $flags)['cards'], 'status', 'id');
}

const DCT_BUILDER_ORDER = ['age', 'storage', 'storage-breakdown', 'battery', 'security', 'stability', 'slowness', 'startup', 'reclaimable', 'upgrade', 'unused-apps', 'backups', 'wifi', 'boot-time', 'crashing-apps', 'updates', 'device-problems', 'remote-access', 'network-settings', 'antivirus-products', 'activation-bitlocker', 'parts', 'verdict'];

describe('groups', function () {
    it('puts each card in its group', function (string $id, string $group) {
        $cards = dctArrange([dctCard($id)])['cards'];

        expect($cards)->toHaveCount(1)
            ->and($cards[0]['group'])->toBe($group);
    })->with([
        ['security', 'Security and backup'],
        ['backups', 'Security and backup'],
        ['updates', 'Security and backup'],
        ['remote-access', 'Security and backup'],
        ['network-settings', 'Security and backup'],
        ['activation-bitlocker', 'Security and backup'],
        ['antivirus-products', 'Security and backup'],
        ['slowness', 'Speed and stability'],
        ['startup', 'Speed and stability'],
        ['boot-time', 'Speed and stability'],
        ['stability', 'Speed and stability'],
        ['crashing-apps', 'Speed and stability'],
        ['device-problems', 'Speed and stability'],
        ['storage', 'Storage'],
        ['storage-breakdown', 'Storage'],
        ['reclaimable', 'Storage'],
        ['unused-apps', 'Storage'],
        ['age', 'Hardware'],
        ['battery', 'Hardware'],
        ['parts', 'Hardware'],
        ['wifi', 'Hardware'],
        ['upgrade', 'Hardware'],
    ]);

    it('orders the cards by group and by their place in the group, whatever order they were built in', function () {
        $cards = dctArrange(array_map(fn (string $id): array => dctCard($id), DCT_BUILDER_ORDER))['cards'];

        expect(array_column($cards, 'id'))->toBe([
            'security', 'backups', 'updates', 'remote-access', 'network-settings', 'activation-bitlocker', 'antivirus-products',
            'slowness', 'startup', 'boot-time', 'stability', 'crashing-apps', 'device-problems',
            'storage', 'storage-breakdown', 'reclaimable', 'unused-apps',
            'age', 'battery', 'parts', 'wifi', 'upgrade',
        ])->and(array_values(array_unique(array_column($cards, 'group'))))->toBe(['Security and backup', 'Speed and stability', 'Storage', 'Hardware']);
    });

    it('puts a card it does not know under Other, after the known groups and in the order they came', function () {
        $cards = dctArrange([dctCard('zeta'), dctCard('battery'), dctCard('alpha'), dctCard('security')])['cards'];

        expect(array_column($cards, 'id'))->toBe(['security', 'battery', 'zeta', 'alpha'])
            ->and(array_column($cards, 'group', 'id'))->toBe(['security' => 'Security and backup', 'battery' => 'Hardware', 'zeta' => 'Other', 'alpha' => 'Other']);
    });

    it('keeps what the card holds and only adds the group and the status', function () {
        $card = [...dctCard('storage-breakdown', []), 'summary' => 'Most of it is photos.', 'bars' => [['title' => 'Biggest folders on the drive', 'items' => []]], 'notes' => ['A note.']];

        expect(dctArrange([$card])['cards'][0])->toBe([...$card, 'group' => 'Storage', 'status' => 'neutral']);
    });
});

describe('the verdict', function () {
    it('takes the worth keeping card out of the list', function () {
        $verdict = dctCard('verdict', ['bad', 'neutral']);
        $arranged = dctArrange([dctCard('age'), $verdict, dctCard('storage')]);

        expect($arranged['verdict'])->toBe($verdict)
            ->and(array_column($arranged['cards'], 'id'))->toBe(['storage', 'age']);
    });

    it('has no verdict when the report gave none', function () {
        expect(dctArrange([dctCard('age')])['verdict'])->toBeNull();
    });

    it('copes with no cards at all', function () {
        expect(dctArrange([], ['FileVault is OFF.']))->toBe(['verdict' => null, 'cards' => []]);
    });
});

describe('status', function () {
    it('needs attention when one row is bad, even next to good rows', function () {
        expect(dctStatuses([dctCard('security', ['good', 'bad', 'good'])]))->toBe(['security' => 'attention']);
    });

    it('is fine with a good row and nothing bad', function () {
        expect(dctStatuses([dctCard('network-settings', ['good', 'neutral'])]))->toBe(['network-settings' => 'fine']);
    });

    it('is neutral with only neutral rows, or no rows at all', function () {
        expect(dctStatuses([dctCard('remote-access', ['neutral', 'neutral']), dctCard('storage-breakdown', [])]))
            ->toBe(['remote-access' => 'neutral', 'storage-breakdown' => 'neutral']);
    });

    it('needs attention when a flag of the report belongs to the card, even with only good rows', function (string $flag, string $id) {
        $statuses = dctStatuses([dctCard($id, ['good']), dctCard('wifi', ['good'])], [$flag]);

        expect($statuses[$id])->toBe('attention')
            ->and($statuses['wifi'])->toBe('fine');
    })->with([
        'mac disk full' => ['Only about 11% of disk is free.', 'storage'],
        'windows drive full' => ['Only about 6% of drive C: is free.', 'storage'],
        'disk health' => ["Disk 'Samsung SSD' health is 'Warning'.", 'storage'],
        'smart' => ["Disk SMART status is 'Failing'.", 'storage'],
        'disk errors' => ['3 disk error event(s) in the last 30 days (bad blocks or controller errors).', 'storage'],
        'ssd wear' => ['Disk 0 SSD wear is 91% (near end of life).', 'storage'],
        'filevault' => ['FileVault is OFF.', 'security'],
        'mac firewall' => ['Application firewall is off.', 'security'],
        'windows firewall' => ['Windows Firewall is off for the Public profile.', 'security'],
        'bitlocker' => ['BitLocker is OFF on C:.', 'security'],
        'sip' => ['System Integrity Protection is DISABLED.', 'security'],
        'gatekeeper' => ['Gatekeeper is DISABLED.', 'security'],
        'defender off' => ['Defender real-time protection is OFF and no other antivirus was found.', 'security'],
        'defender old' => ['Defender signatures are 30 days old.', 'security'],
        'tamper' => ['Defender tamper protection is off.', 'security'],
        'smbv1' => ['SMBv1 is enabled (an outdated file sharing protocol that ransomware has abused).', 'security'],
        'rdp' => ['Remote Desktop (RDP) is enabled, so people on the network can try to sign in to this PC.', 'security'],
        'no password' => ['2 enabled account(s) are not required to have a password (see Administrator accounts section).', 'security'],
        'admins' => ['4 accounts have administrator rights (see Administrator accounts section).', 'security'],
        'secure boot' => ['Secure Boot is OFF.', 'security'],
        'no tpm' => ['No TPM found (Windows 11 needs TPM 2.0).', 'security'],
        'tpm not ready' => ['TPM is present but not ready.', 'security'],
        'activation lock' => ["Activation Lock is ENABLED. Needs the previous owner's Apple ID to be removed.", 'security'],
        'remote login' => ['Remote Login (SSH) is on, so other computers can try to sign in to this Mac.', 'security'],
        'screen sharing' => ['Screen Sharing is on, so other people on the network can ask to see or control this Mac.', 'security'],
        'auto sign-in' => ['Automatic sign-in is on, so anyone who starts this computer gets in without a password.', 'security'],
        'managed' => ['Enrolled in MDM (managed by an organisation).', 'security'],
        'no backup' => ['No backup was found (Time Machine is not set up).', 'backups'],
        'old backup' => ['The last backup was 90 days ago (File History).', 'backups'],
        'update age' => ['No macOS update has been installed for 1748 days (newest: 2022-01-26).', 'updates'],
        'mac updates pending' => ['Software updates are pending (see Software updates section).', 'updates'],
        'windows updates pending' => ['Windows updates are pending (see Windows Update section): 4.', 'updates'],
        'restart pending' => ['A restart is pending (Windows Update).', 'updates'],
        'old bios' => ['The BIOS (firmware) is 7 years old (released 2019-03-01).', 'updates'],
        'agents' => ['Security, management or remote access agents running (can slow a PC or give someone else access): AnyDesk', 'remote-access'],
        'not activated' => ['Windows is not activated (status: Notification).', 'activation-bitlocker'],
        'unexpected shutdowns' => ['2 unexpected shutdown(s) in the last 30 days (crash, power loss or forced power off).', 'stability'],
        'kernel panics' => ['3 kernel panic report(s) found in /Library/Logs/DiagnosticReports.', 'stability'],
        'not restarted' => ['Not restarted for 41 days.', 'stability'],
        'up for' => ['Up for 33 days since the last full boot (Fast Startup can inflate this).', 'stability'],
        'low ram' => ['Only 4 GB of RAM.', 'slowness'],
        'short memory' => ['Only 9% of memory is free (high memory pressure).', 'slowness'],
        'spinning' => ['Only spinning hard drives found (no SSD), so the PC will feel slow.', 'slowness'],
        'load' => ['1 minute load average 9.2 exceeds the 4 CPU cores.', 'slowness'],
        'startup programs' => ['22 programs start with Windows (slows boot).', 'startup'],
        'drivers' => ['2 device(s) report a driver problem in Device Manager (see Devices with problems section).', 'device-problems'],
        'battery' => ['Battery has 912 cycles (high).', 'battery'],
        'unsupported os' => ['macOS 10.14.6 is old and likely out of security support.', 'age'],
    ]);

    it('ignores a flag that belongs to no card, or to a card the report does not have', function (string $flag) {
        expect(dctStatuses([dctCard('wifi', ['good']), dctCard('remote-access')], [$flag]))->toBe(['remote-access' => 'neutral', 'wifi' => 'fine']);
    })->with([
        'unknown text' => ['Something nobody has seen before.'],
        'empty' => [''],
        'unicode and control characters' => ["Schijf \u{1F4BE} \x07\x1b[31mvol\0"],
        'card not in this report' => ['FileVault is OFF.'],
    ]);

    it('counts a repeated flag once and leaves the other cards alone', function () {
        expect(dctStatuses([dctCard('storage', ['neutral']), dctCard('security', ['good'])], ['Only about 4% of disk is free.', 'Only about 4% of disk is free.']))
            ->toBe(['security' => 'fine', 'storage' => 'attention']);
    });
});

describe('the flag to card map', function () {
    it('only names rules FlagAdvisor knows and cards that have a group', function () {
        $known = array_merge(...array_values(DetailCardTabs::GROUPS));

        expect(array_diff(array_keys(FlagChecks::CARDS), array_keys(FlagChecks::SECTIONS)))->toBe([])
            ->and(array_diff(FlagChecks::CARDS, $known))->toBe([]);
    });
});
