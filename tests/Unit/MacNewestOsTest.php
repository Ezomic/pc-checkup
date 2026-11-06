<?php

declare(strict_types=1);

use App\Services\Reports\MacNewestOs;

it('looks up the newest macOS for a model identifier in each family', function (string $model, string $version, string $name) {
    expect(MacNewestOs::forModel($model))->toBe($version)
        ->and(MacNewestOs::name($version))->toBe($name);
})->with([
    'MacBook Air M1' => ['MacBook Air (MacBookAir10,1)', '27', 'macOS 27 Golden Gate'],
    'MacBook Air Intel 2020' => ['MacBook Air (MacBookAir9,1)', '15', 'macOS 15 Sequoia'],
    'MacBook Air 2018' => ['MacBook Air (MacBookAir8,1)', '14', 'macOS 14 Sonoma'],
    'MacBook Air 2015' => ['MacBook Air (MacBookAir7,2)', '12', 'macOS 12 Monterey'],
    'MacBook Pro 16 inch 2019' => ['MacBook Pro (MacBookPro16,1)', '26', 'macOS 26 Tahoe'],
    'MacBook Pro 2017' => ['MacBook Pro (MacBookPro14,1)', '13', 'macOS 13 Ventura'],
    'MacBook Pro 2012' => ['MacBook Pro (MacBookPro9,2)', '10.15', 'macOS 10.15 Catalina'],
    'MacBook Pro 2010' => ['MacBook Pro (MacBookPro7,1)', '10.13', 'macOS 10.13 High Sierra'],
    'MacBook Pro 2008' => ['MacBook Pro (MacBookPro4,1)', '10.11', 'OS X 10.11 El Capitan'],
    'MacBook 2016' => ['MacBook (MacBook9,1)', '12', 'macOS 12 Monterey'],
    'MacBook 2015' => ['MacBook (MacBook8,1)', '11', 'macOS 11 Big Sur'],
    'Mac mini M1' => ['Mac mini (Macmini9,1)', '27', 'macOS 27 Golden Gate'],
    'Mac mini 2018' => ['Mac mini (Macmini8,1)', '15', 'macOS 15 Sequoia'],
    'Mac mini 2012' => ['Mac mini (Macmini6,2)', '10.15', 'macOS 10.15 Catalina'],
    'iMac 24 inch M1' => ['iMac (iMac21,1)', '27', 'macOS 27 Golden Gate'],
    'iMac 27 inch 2020' => ['iMac (iMac20,1)', '26', 'macOS 26 Tahoe'],
    'iMac 2017' => ['iMac (iMac18,3)', '13', 'macOS 13 Ventura'],
    'iMac 2011' => ['iMac (iMac12,2)', '10.13', 'macOS 10.13 High Sierra'],
    'iMac Pro' => ['iMac Pro (iMacPro1,1)', '15', 'macOS 15 Sequoia'],
    'Mac Studio' => ['Mac Studio (Mac13,1)', '27', 'macOS 27 Golden Gate'],
    'Mac Pro 2023' => ['Mac Pro (Mac14,8)', '27', 'macOS 27 Golden Gate'],
    'Mac Pro 2019' => ['Mac Pro (MacPro7,1)', '26', 'macOS 26 Tahoe'],
    'Mac Pro 2013' => ['Mac Pro (MacPro6,1)', '12', 'macOS 12 Monterey'],
    'Mac Pro 2010' => ['Mac Pro (MacPro5,1)', '10.14', 'macOS 10.14 Mojave'],
    'a newer Apple silicon identifier' => ['MacBook Pro (Mac17,2)', '27', 'macOS 27 Golden Gate'],
]);

it('says nothing for an identifier it does not know', function (?string $model) {
    expect(MacNewestOs::forModel($model))->toBeNull();
})->with([
    'a virtual machine' => ['VMware (VMware7,1)'],
    'an invented model' => ['Mac (MacBookAir99,9)'],
    'no identifier' => ['Contoso Voyager 14'],
    'a model without a trailing identifier' => ['MacBook Air (MacBookAir10,1) extra'],
    'the 2017 MacBook Apple lists no macOS for' => ['MacBook (MacBook10,1)'],
    'empty' => [''],
    'nothing' => [null],
]);

it('has a name for the versions the table uses and none for an unknown one', function () {
    expect(MacNewestOs::name('99'))->toBeNull()
        ->and(MacNewestOs::name('10.12'))->toBe('macOS 10.12 Sierra');
});

it('reads the running version down to its major release', function (?string $raw, ?string $expected) {
    expect(MacNewestOs::running($raw))->toBe($expected);
})->with([
    'a current release' => ['14.5 (23F79)', '14'],
    'a release with a patch number' => ['15.0.1', '15'],
    'an old release' => ['10.14.6 (18G9323)', '10.14'],
    'an old release without a minor' => ['10', '10.0'],
    'a bare major' => ['26', '26'],
    'padded' => ['  13.2  ', '13'],
    'words' => ['Sonoma', null],
    'empty' => ['', null],
    'nothing' => [null, null],
]);

it('knows which releases still get security updates', function () {
    expect(MacNewestOs::isSupportedByApple('14'))->toBeTrue()
        ->and(MacNewestOs::isSupportedByApple('27'))->toBeTrue()
        ->and(MacNewestOs::isSupportedByApple('13'))->toBeFalse()
        ->and(MacNewestOs::isSupportedByApple('10.15'))->toBeFalse();
});
