<?php

declare(strict_types=1);

namespace App\Services\Checklist;

use App\Models\Machine;

final class ChecklistResolver
{
    private const COMMON = [
        'serial_matches' => 'Serial number matches the sticker or box',
        'case_condition' => 'Case has no dents, cracks or liquid damage',
        'screen' => 'Screen has no dead pixels, bright spots or burn-in',
        'ports' => 'Every port works (USB, video, audio, charging)',
        'wireless' => 'Wi-Fi and Bluetooth connect',
        'audio' => 'Speakers and microphone work',
        'stress_test' => 'Ran a 10 minute load without throttling, shutdown or loud fans',
        'previous_owner_data' => 'No previous owner accounts, files or saved passwords left behind',
        'backup' => 'A backup exists before anything is changed',
        'licences' => 'Paid software tied to the previous owner is reassigned',
    ];

    private const LAPTOP = [
        'battery_swelling' => 'Battery is not swollen (trackpad flat, case not bulging)',
        'keyboard' => 'Every key on the keyboard works',
        'pointer' => 'Trackpad and click work',
        'hinge' => 'Hinge is firm and the lid closes flat',
        'webcam' => 'Camera works',
        'charger' => 'Charger charges the battery',
    ];

    private const DESKTOP = [
        'fans' => 'Fans spin evenly with no grinding',
        'power_button' => 'Power button and power supply work',
    ];

    private const MACOS = [
        'apple_diagnostics' => 'Apple Diagnostics passed',
        'activation_lock' => 'Activation Lock is off and no previous Apple ID is signed in',
        'repair_history' => 'Repair history shows no non-genuine parts warning',
        'coverage_lookup' => 'Serial checked at checkcoverage.apple.com',
        'erase' => 'Erase All Content and Settings done for a clean start',
    ];

    private const WINDOWS = [
        'secure_boot_tpm' => 'Secure Boot and TPM are enabled',
        'activation' => 'Windows is activated with a genuine licence',
        'firmware_password' => 'No BIOS or UEFI password is set',
        'device_manager' => 'Device Manager shows no unknown devices',
        'disk_health' => 'Disk health (SMART) is good',
        'bitlocker' => 'BitLocker recovery key is known, or the disk is decrypted',
    ];

    /**
     * @return list<array{key: string, label: string, group: string}>
     */
    public function for(Machine $machine): array
    {
        $groups = ['common' => self::COMMON];

        $deviceType = mb_strtolower((string) $machine->device_type);
        if ($deviceType === 'laptop') {
            $groups['laptop'] = self::LAPTOP;
        } elseif ($deviceType === 'desktop') {
            $groups['desktop'] = self::DESKTOP;
        }

        $os = mb_strtolower((string) $machine->os);
        if (str_starts_with($os, 'macos')) {
            $groups['macos'] = self::MACOS;
        } elseif (str_starts_with($os, 'windows')) {
            $groups['windows'] = self::WINDOWS;
        }

        $items = [];
        foreach ($groups as $group => $labels) {
            foreach ($labels as $key => $label) {
                $items[] = ['key' => $key, 'label' => $label, 'group' => $group];
            }
        }

        return $items;
    }
}
