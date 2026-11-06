<?php

declare(strict_types=1);

namespace App\Services\Reports;

final class MacNewestOs
{
    public const OLDEST_SUPPORTED_MAJOR = '14';

    private const NAMES = [
        '27' => 'macOS 27 Golden Gate',
        '26' => 'macOS 26 Tahoe',
        '15' => 'macOS 15 Sequoia',
        '14' => 'macOS 14 Sonoma',
        '13' => 'macOS 13 Ventura',
        '12' => 'macOS 12 Monterey',
        '11' => 'macOS 11 Big Sur',
        '10.15' => 'macOS 10.15 Catalina',
        '10.14' => 'macOS 10.14 Mojave',
        '10.13' => 'macOS 10.13 High Sierra',
        '10.12' => 'macOS 10.12 Sierra',
        '10.11' => 'OS X 10.11 El Capitan',
    ];

    /**
     * The newest macOS Apple lists for a Model Identifier on its "Identify your ... model" support
     * pages (MacBook Air, MacBook Pro, MacBook, Mac mini, iMac, iMac Pro, Mac Studio, Mac Pro).
     * An identifier missing from the list has no answer rather than a guess.
     *
     * @var array<string, string>
     */
    private const NEWEST = [
        'MacBookAir10,1' => '27', 'Mac14,2' => '27', 'Mac14,15' => '27', 'Mac15,12' => '27',
        'Mac15,13' => '27', 'Mac16,12' => '27', 'Mac16,13' => '27', 'Mac17,3' => '27', 'Mac17,4' => '27',
        'Mac18,1' => '27', 'Mac17,11' => '27', 'Mac17,13' => '27', 'Mac17,10' => '27', 'Mac17,12' => '27',
        'Mac17,7' => '27', 'Mac17,9' => '27', 'Mac17,6' => '27', 'Mac17,8' => '27', 'Mac17,2' => '27',
        'Mac16,1' => '27', 'Mac16,6' => '27', 'Mac16,8' => '27', 'Mac16,7' => '27', 'Mac16,5' => '27',
        'Mac15,3' => '27', 'Mac15,6' => '27', 'Mac15,8' => '27', 'Mac15,10' => '27', 'Mac15,7' => '27',
        'Mac15,9' => '27', 'Mac15,11' => '27', 'Mac14,5' => '27', 'Mac14,9' => '27', 'Mac14,6' => '27',
        'Mac14,10' => '27', 'Mac14,7' => '27', 'MacBookPro18,1' => '27', 'MacBookPro18,2' => '27',
        'MacBookPro18,3' => '27', 'MacBookPro18,4' => '27', 'MacBookPro17,1' => '27', 'Mac18,5' => '27',
        'Mac17,16' => '27', 'Mac16,10' => '27', 'Mac16,11' => '27', 'Mac14,3' => '27', 'Mac14,12' => '27',
        'Macmini9,1' => '27', 'Mac18,2' => '27', 'Mac16,3' => '27', 'Mac16,2' => '27', 'Mac15,5' => '27',
        'Mac15,4' => '27', 'iMac21,1' => '27', 'iMac21,2' => '27', 'Mac17,14' => '27', 'Mac17,15' => '27',
        'Mac16,9' => '27', 'Mac15,14' => '27', 'Mac14,13' => '27', 'Mac14,14' => '27', 'Mac13,1' => '27',
        'Mac13,2' => '27', 'Mac14,8' => '27',
        'MacBookPro16,1' => '26', 'MacBookPro16,2' => '26', 'MacBookPro16,4' => '26', 'iMac20,1' => '26',
        'iMac20,2' => '26', 'MacPro7,1' => '26',
        'MacBookAir9,1' => '15', 'MacBookPro16,3' => '15', 'MacBookPro15,1' => '15',
        'MacBookPro15,2' => '15', 'MacBookPro15,3' => '15', 'MacBookPro15,4' => '15', 'Macmini8,1' => '15',
        'iMac19,1' => '15', 'iMac19,2' => '15', 'iMacPro1,1' => '15',
        'MacBookAir8,1' => '14', 'MacBookAir8,2' => '14',
        'MacBookPro14,1' => '13', 'MacBookPro14,2' => '13', 'MacBookPro14,3' => '13', 'iMac18,1' => '13',
        'iMac18,2' => '13', 'iMac18,3' => '13',
        'MacBookAir7,1' => '12', 'MacBookAir7,2' => '12', 'MacBookPro11,4' => '12',
        'MacBookPro11,5' => '12', 'MacBookPro12,1' => '12', 'MacBookPro13,1' => '12',
        'MacBookPro13,2' => '12', 'MacBookPro13,3' => '12', 'MacBook9,1' => '12', 'Macmini7,1' => '12',
        'iMac16,1' => '12', 'iMac16,2' => '12', 'iMac17,1' => '12', 'MacPro6,1' => '12',
        'MacBookAir6,1' => '11', 'MacBookAir6,2' => '11', 'MacBookPro11,1' => '11',
        'MacBookPro11,2' => '11', 'MacBookPro11,3' => '11', 'MacBook8,1' => '11', 'iMac14,4' => '11',
        'iMac15,1' => '11',
        'MacBookAir5,1' => '10.15', 'MacBookAir5,2' => '10.15', 'MacBookPro9,1' => '10.15',
        'MacBookPro9,2' => '10.15', 'MacBookPro10,1' => '10.15', 'MacBookPro10,2' => '10.15',
        'Macmini6,1' => '10.15', 'Macmini6,2' => '10.15', 'iMac13,1' => '10.15', 'iMac13,2' => '10.15',
        'iMac14,1' => '10.15', 'iMac14,2' => '10.15',
        'MacPro5,1' => '10.14',
        'MacBookAir3,1' => '10.13', 'MacBookAir3,2' => '10.13', 'MacBookAir4,1' => '10.13',
        'MacBookAir4,2' => '10.13', 'MacBookPro6,1' => '10.13', 'MacBookPro6,2' => '10.13',
        'MacBookPro7,1' => '10.13', 'MacBookPro8,1' => '10.13', 'MacBookPro8,2' => '10.13',
        'MacBookPro8,3' => '10.13', 'MacBook6,1' => '10.13', 'MacBook7,1' => '10.13',
        'Macmini4,1' => '10.13', 'Macmini5,1' => '10.13', 'Macmini5,2' => '10.13', 'iMac10,1' => '10.13',
        'iMac11,2' => '10.13', 'iMac11,3' => '10.13', 'iMac12,1' => '10.13', 'iMac12,2' => '10.13',
        'MacBookAir2,1' => '10.11', 'MacBookPro4,1' => '10.11', 'MacBookPro5,1' => '10.11',
        'MacBookPro5,2' => '10.11', 'MacBookPro5,3' => '10.11', 'MacBookPro5,5' => '10.11',
        'MacBook5,2' => '10.11', 'Macmini3,1' => '10.11', 'iMac9,1' => '10.11', 'MacPro4,1' => '10.11',
    ];

    public static function forModel(?string $model): ?string
    {
        if ($model === null || preg_match('/\(([A-Za-z]+\d+,\d+)\)$/', $model, $match) !== 1) {
            return null;
        }

        return self::NEWEST[$match[1]] ?? null;
    }

    public static function name(string $version): ?string
    {
        return self::NAMES[$version] ?? null;
    }

    public static function running(?string $version): ?string
    {
        if ($version === null || preg_match('/^(\d{1,2})(?:\.(\d{1,2}))?/', trim($version), $match) !== 1) {
            return null;
        }

        return $match[1] === '10' ? '10.'.($match[2] ?? '0') : $match[1];
    }

    public static function isSupportedByApple(string $version): bool
    {
        return version_compare($version, self::OLDEST_SUPPORTED_MAJOR, '>=');
    }
}
