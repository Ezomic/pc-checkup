<?php

declare(strict_types=1);

namespace App\Services\Reports;

final class MacModelYears
{
    /**
     * The year a Model identifier first shipped. A refresh can reuse an identifier, so this is
     * "first released", and an identifier missing from the list has no year rather than a guess.
     *
     * @var array<string, int>
     */
    private const FIRST_RELEASED = [
        'MacBookAir4,1' => 2011, 'MacBookAir4,2' => 2011, 'MacBookAir5,1' => 2012, 'MacBookAir5,2' => 2012,
        'MacBookAir6,1' => 2013, 'MacBookAir6,2' => 2013, 'MacBookAir7,1' => 2015, 'MacBookAir7,2' => 2015,
        'MacBookAir8,1' => 2018, 'MacBookAir8,2' => 2019, 'MacBookAir9,1' => 2020, 'MacBookAir10,1' => 2020,
        'MacBookPro8,1' => 2011, 'MacBookPro8,2' => 2011, 'MacBookPro8,3' => 2011, 'MacBookPro9,1' => 2012,
        'MacBookPro9,2' => 2012, 'MacBookPro10,1' => 2012, 'MacBookPro10,2' => 2012, 'MacBookPro11,1' => 2013,
        'MacBookPro11,2' => 2013, 'MacBookPro11,3' => 2013, 'MacBookPro11,4' => 2015, 'MacBookPro11,5' => 2015,
        'MacBookPro12,1' => 2015, 'MacBookPro13,1' => 2016, 'MacBookPro13,2' => 2016, 'MacBookPro13,3' => 2016,
        'MacBookPro14,1' => 2017, 'MacBookPro14,2' => 2017, 'MacBookPro14,3' => 2017, 'MacBookPro15,1' => 2018,
        'MacBookPro15,2' => 2018, 'MacBookPro15,3' => 2018, 'MacBookPro15,4' => 2019, 'MacBookPro16,1' => 2019,
        'MacBookPro16,2' => 2020, 'MacBookPro16,3' => 2020, 'MacBookPro16,4' => 2019, 'MacBookPro17,1' => 2020,
        'MacBookPro18,1' => 2021, 'MacBookPro18,2' => 2021, 'MacBookPro18,3' => 2021, 'MacBookPro18,4' => 2021,
        'MacBook7,1' => 2010, 'MacBook8,1' => 2015, 'MacBook9,1' => 2016, 'MacBook10,1' => 2017,
        'Macmini6,1' => 2012, 'Macmini6,2' => 2012, 'Macmini7,1' => 2014, 'Macmini8,1' => 2018, 'Macmini9,1' => 2020,
        'iMac12,1' => 2011, 'iMac12,2' => 2011, 'iMac13,1' => 2012, 'iMac13,2' => 2012, 'iMac14,1' => 2013,
        'iMac14,2' => 2013, 'iMac14,3' => 2013, 'iMac14,4' => 2014, 'iMac15,1' => 2014, 'iMac16,1' => 2015,
        'iMac16,2' => 2015, 'iMac17,1' => 2015, 'iMac18,1' => 2017, 'iMac18,2' => 2017, 'iMac18,3' => 2017,
        'iMac19,1' => 2019, 'iMac19,2' => 2019, 'iMac20,1' => 2020, 'iMac20,2' => 2020, 'iMac21,1' => 2021,
        'iMac21,2' => 2021, 'iMacPro1,1' => 2017, 'MacPro6,1' => 2013, 'MacPro7,1' => 2019,
        'Mac13,1' => 2022, 'Mac13,2' => 2022, 'Mac14,2' => 2022, 'Mac14,7' => 2022, 'Mac14,3' => 2023,
        'Mac14,12' => 2023, 'Mac14,5' => 2023, 'Mac14,6' => 2023, 'Mac14,9' => 2023, 'Mac14,10' => 2023,
        'Mac14,15' => 2023,
    ];

    public static function year(string $identifier): ?int
    {
        return self::FIRST_RELEASED[$identifier] ?? null;
    }
}
