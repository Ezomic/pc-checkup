<?php

declare(strict_types=1);

return [
    'disk_problem' => [
        'headline' => 'A disk is reporting problems',
        'advice' => 'Back up your files now, then have the drive checked or replaced.',
    ],
    'disk_errors' => [
        'headline' => 'Disk errors were logged',
        'advice' => 'Bad blocks or controller errors can mean the drive is failing. Back up your files and check the drive.',
    ],
    'ssd_wear' => [
        'headline' => 'An SSD is :percent% worn out',
        'advice' => 'It is near the end of its life. Back up your files and plan a replacement.',
    ],
    'unexpected_shutdowns' => [
        'headline' => 'Crashed or lost power :count times this month',
        'advice' => 'Check the power supply, temperatures and memory. Repeated crashes often point to failing hardware.',
    ],
    'kernel_panics' => [
        'headline' => 'The Mac crashed :count times',
        'advice' => 'Repeated kernel panics usually mean a hardware or driver fault.',
    ],
    'drive_full' => [
        'headline' => 'Drive :drive is almost full, only :percent% left',
        'advice' => 'A full drive slows the machine down and can stop updates. Free up space or move files elsewhere.',
    ],
    'disk_full' => [
        'headline' => 'The disk is almost full, only :percent% left',
        'advice' => 'A full drive slows the machine down and can stop updates. Free up space or move files elsewhere.',
    ],
    'no_antivirus' => [
        'headline' => 'No antivirus is protecting this PC',
        'advice' => 'Turn on real-time protection in Windows Security, or install an antivirus.',
    ],
    'unsupported_os' => [
        'headline' => 'The operating system is no longer supported',
        'advice' => 'It no longer gets free security updates. Upgrade it or replace the machine.',
    ],
    'sip_disabled' => [
        'headline' => 'A core macOS protection is turned off',
        'advice' => 'Turn System Integrity Protection back on unless you disabled it on purpose.',
    ],
    'gatekeeper_disabled' => [
        'headline' => 'macOS is not checking apps before they run',
        'advice' => 'Turn Gatekeeper back on in Privacy and Security.',
    ],
    'smbv1' => [
        'headline' => 'An outdated file sharing feature is on',
        'advice' => 'SMBv1 is decades old and ransomware has used it to spread. Turn it off in Windows Features unless an old device needs it.',
    ],
    'rdp' => [
        'headline' => 'Remote Desktop is turned on',
        'advice' => 'It lets people sign in to this PC over the network. Turn it off in Settings under System, Remote Desktop, unless you use it.',
    ],
    'no_password_accounts' => [
        'headline' => 'Some accounts need no password',
        'advice' => 'These accounts are allowed to have an empty password. Make sure each one has a password.',
    ],
    'windows_not_activated' => [
        'headline' => 'Windows is not activated',
        'advice' => 'Activate it with a valid licence to get every feature and update.',
    ],
    'encryption_off' => [
        'headline' => 'Disk encryption is off',
        'advice' => 'If the machine is lost or stolen, anyone can read its files. Turn disk encryption on.',
    ],
    'secure_boot' => [
        'headline' => 'Secure Boot is off',
        'advice' => 'It blocks malware that starts before Windows. Turn it on in the BIOS or UEFI settings.',
    ],
    'no_tpm' => [
        'headline' => 'No TPM found',
        'advice' => 'Windows 11 needs TPM 2.0. Look for fTPM or PTT in the BIOS, or this machine cannot officially run Windows 11.',
    ],
    'tpm_not_ready' => [
        'headline' => 'The TPM is not ready',
        'advice' => 'Initialise it in Windows Security under Device security.',
    ],
    'firewall_off' => [
        'headline' => 'The firewall is off',
        'advice' => 'Turn it on so unwanted connections are blocked.',
    ],
    'defender_old' => [
        'headline' => 'Virus definitions are :days days old',
        'advice' => 'Run Windows Update so protection stays current.',
    ],
    'windows_updates_pending' => [
        'headline' => ':count Windows updates are waiting',
        'advice' => 'Install them. Updates carry security fixes.',
    ],
    'software_updates_pending' => [
        'headline' => 'Software updates are waiting',
        'advice' => 'Install them. Updates carry security fixes.',
    ],
    'low_ram' => [
        'headline' => 'Only :gb GB of RAM',
        'advice' => 'Adding memory is the cheapest speed-up for an older machine.',
    ],
    'short_memory' => [
        'headline' => 'The machine is short on memory',
        'advice' => 'Close heavy apps or add RAM.',
    ],
    'spinning_drive' => [
        'headline' => 'It only has a spinning hard drive',
        'advice' => 'An SSD makes an older machine feel much faster.',
    ],
    'driver_problems' => [
        'headline' => ':count devices have driver problems',
        'advice' => 'Open Device Manager and update or reinstall the drivers.',
    ],
    'battery_worn' => [
        'headline' => 'The battery is worn',
        'advice' => 'It holds less charge than when it was new. Consider a replacement.',
    ],
    'activation_lock' => [
        'headline' => 'Activation Lock is on',
        'advice' => 'The previous owner\'s Apple ID must be removed before this Mac can be reset or sold.',
    ],
    'remote_login' => [
        'headline' => 'Remote Login is turned on',
        'advice' => 'Other computers can try to sign in to this Mac. Turn it off in System Settings under General, Sharing, unless you use it.',
    ],
    'screen_sharing' => [
        'headline' => 'Screen Sharing is turned on',
        'advice' => 'Other people on the network can ask to see or control this Mac. Turn it off in System Settings under General, Sharing, unless you use it.',
    ],
    'auto_signin' => [
        'headline' => 'The computer signs in by itself',
        'advice' => 'Anyone who switches it on can open your files. Turn automatic sign-in off unless this is a shared machine on purpose.',
    ],
    'no_backup' => [
        'headline' => 'No backup was found',
        'advice' => 'If the drive fails or the machine is lost, the files are gone. Set up a backup to an external drive or the cloud.',
    ],
    'old_backup' => [
        'headline' => 'The last backup was :days days ago',
        'advice' => 'Connect the backup drive or check that the backup still runs, so recent files are covered.',
    ],
    'no_updates_installed' => [
        'headline' => 'No updates installed for :days days',
        'advice' => 'Install the available updates. They carry security fixes.',
    ],
    'many_admins' => [
        'headline' => ':count accounts have administrator rights',
        'advice' => 'Fewer administrators means less damage if one account is taken over. Make everyday accounts standard users.',
    ],
    'old_bios' => [
        'headline' => 'The BIOS is :years years old',
        'advice' => 'Check the maker\'s website for a firmware update. Newer versions fix security and stability problems.',
    ],
    'tamper_protection' => [
        'headline' => 'Tamper protection is off',
        'advice' => 'Turn it on in Windows Security so malware cannot switch protection off.',
    ],
    'restart_pending' => [
        'headline' => 'A restart is pending',
        'advice' => 'Restart to finish installing updates.',
    ],
    'not_restarted' => [
        'headline' => 'Not restarted in :days days',
        'advice' => 'Restarting now and then clears out problems and applies updates.',
    ],
    'startup_programs' => [
        'headline' => ':count apps start with Windows',
        'advice' => 'Turn off the ones you do not need in Task Manager under Startup.',
    ],
    'remote_agents' => [
        'headline' => 'Remote access or management software is running',
        'advice' => 'Make sure you recognise it. Some tools let other people control the machine.',
    ],
    'managed' => [
        'headline' => 'It looks managed by an organisation',
        'advice' => 'Work or school policies may limit what you can change.',
    ],
    'running_slow' => [
        'headline' => 'It is running slowly right now',
        'advice' => 'Heavy load, low-power mode or heat is slowing it down. Check again later.',
    ],
    'unknown' => [
        'headline' => 'Something to look at',
    ],
];
