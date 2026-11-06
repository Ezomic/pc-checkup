<?php

declare(strict_types=1);

use App\Services\Reports\CpuGeneration;

it('judges the processor from its name', function (string $name, string $expected) {
    expect(CpuGeneration::judge($name))->toBe($expected);
})->with([
    'Intel 8th generation U' => ['Intel(R) Core(TM) i5-8250U CPU @ 1.60GHz', 'ok'],
    'Intel 7th generation' => ['Intel(R) Core(TM) i7-7700HQ CPU @ 2.80GHz', 'old'],
    'Intel 11th generation with four digits' => ['11th Gen Intel(R) Core(TM) i7-1165G7 @ 2.80GHz', 'ok'],
    'Intel 10th generation with four digits' => ['Intel(R) Core(TM) i5-1035G1 CPU @ 1.00GHz', 'ok'],
    'Intel 12th generation with four digits' => ['12th Gen Intel(R) Core(TM) i5-1235U', 'ok'],
    'Intel 13th generation with five digits' => ['13th Gen Intel(R) Core(TM) i9-13900K', 'ok'],
    'Intel 10th generation with five digits' => ['Intel(R) Core(TM) i7-10510U CPU @ 1.80GHz', 'ok'],
    'Intel 9th generation' => ['Intel(R) Core(TM) i9-9900K CPU @ 3.60GHz', 'ok'],
    'Intel 6th generation' => ['Intel(R) Core(TM) i5-6200U CPU @ 2.30GHz', 'old'],
    'Intel 2nd generation' => ['Intel(R) Core(TM) i7-2600 CPU @ 3.40GHz', 'old'],
    'Intel first generation with three digits' => ['Intel(R) Core(TM) i7 920 @ 2.67GHz', 'old'],
    'Intel first generation, M series' => ['Intel(R) Core(TM) i5 CPU M 520 @ 2.40GHz', 'old'],
    'Intel first generation without a letter' => ['Intel(R) Core(TM) i7 CPU 860 @ 2.80GHz', 'old'],
    'Intel Core 2 Duo' => ['Intel(R) Core(TM)2 Duo CPU E8400 @ 3.00GHz', 'old'],
    'Intel Core 2 Quad' => ['Intel(R) Core(TM)2 Quad CPU Q6600', 'old'],
    'Intel Core Ultra' => ['Intel(R) Core(TM) Ultra 5 125H', 'ok'],
    'Intel Core with the new numbering' => ['Intel(R) Core(TM) 5 120U', 'ok'],
    'Intel N series' => ['Intel(R) N100', 'ok'],
    'Intel i3 N series' => ['Intel(R) Core(TM) i3-N305', 'ok'],
    'Intel with an absurd generation' => ['Intel(R) Core(TM) i7-99999', 'unknown'],
    'Intel Pentium' => ['Intel(R) Pentium(R) Gold G5400 CPU @ 3.70GHz', 'unknown'],
    'Intel Celeron' => ['Intel(R) Celeron(R) N4020 CPU @ 1.10GHz', 'unknown'],
    'Intel Atom' => ['Intel(R) Atom(TM) x5-Z8350 CPU @ 1.44GHz', 'unknown'],
    'Intel Xeon' => ['Intel(R) Xeon(R) CPU E3-1230 v5 @ 3.40GHz', 'unknown'],
    'AMD Ryzen 1000 series' => ['AMD Ryzen 5 1600 Six-Core Processor', 'old'],
    'AMD Ryzen 2000 series mobile' => ['AMD Ryzen 5 2500U with Radeon Vega Mobile Gfx', 'ok'],
    'AMD Ryzen 3000 series mobile' => ['AMD Ryzen 5 3500U with Radeon Vega Mobile Gfx', 'ok'],
    'AMD Ryzen 5000 series' => ['AMD Ryzen 7 5800X 8-Core Processor', 'ok'],
    'AMD Ryzen PRO' => ['AMD Ryzen 5 PRO 4650U with Radeon Graphics', 'ok'],
    'AMD Ryzen 7000 series' => ['AMD Ryzen 9 7940HS w/ Radeon 780M Graphics', 'ok'],
    'AMD Ryzen AI' => ['AMD Ryzen AI 9 HX 370 w/ Radeon 890M', 'ok'],
    'AMD Threadripper first generation' => ['AMD Ryzen Threadripper 1950X 16-Core Processor', 'old'],
    'AMD Threadripper PRO' => ['AMD Ryzen Threadripper PRO 5995WX 64-Cores', 'ok'],
    'AMD Ryzen with an unknown scheme' => ['AMD Ryzen 5 340 6-Core Processor', 'unknown'],
    'AMD FX' => ['AMD FX(tm)-8350 Eight-Core Processor', 'old'],
    'AMD Phenom' => ['AMD Phenom(tm) II X4 955 Processor', 'old'],
    'AMD A series' => ['AMD A10-7800 Radeon R7, 12 Compute Cores 4C+8G', 'old'],
    'AMD Athlon 64' => ['AMD Athlon(tm) 64 X2 Dual Core Processor 5200+', 'old'],
    'AMD Athlon Silver' => ['AMD Athlon Silver 3050U with Radeon Graphics', 'unknown'],
    'Qualcomm' => ['Snapdragon (TM) 8cx Gen 3 @ 3.0 GHz', 'unknown'],
    'a vendor with no model' => ['Intel(R) CPU', 'unknown'],
    'an empty name' => ['', 'unknown'],
    'whitespace' => ["  \t ", 'unknown'],
    'control characters' => ["\u{0000}\u{0007}Intel\u{001B}[31m", 'unknown'],
    'unicode noise' => ['プロセッサ Intel 😀 i7', 'unknown'],
    'both vendors named' => ['Intel AMD Core i7-8700 Ryzen 5 3600', 'unknown'],
]);

it('treats a missing name as unknown', function () {
    expect(CpuGeneration::judge(null))->toBe('unknown');
});

it('distrusts a name that contradicts the maker', function (string $name, string $maker, string $expected) {
    expect(CpuGeneration::judge($name, $maker))->toBe($expected);
})->with([
    'Intel name, Intel maker' => ['Intel(R) Core(TM) i5-8250U', 'GenuineIntel', 'ok'],
    'Intel name, AMD maker' => ['Intel(R) Core(TM) i5-8250U', 'AuthenticAMD', 'unknown'],
    'Ryzen name, Intel maker' => ['AMD Ryzen 5 3500U', 'GenuineIntel', 'unknown'],
    'Ryzen name, AMD maker' => ['AMD Ryzen 5 3500U', 'AuthenticAMD', 'ok'],
    'an unrecognised maker' => ['Intel(R) Core(TM) i5-8250U', 'Contoso', 'ok'],
    'an AMD maker spelled out' => ['Intel(R) Core(TM) i5-8250U', 'Advanced Micro Devices, Inc.', 'unknown'],
]);
