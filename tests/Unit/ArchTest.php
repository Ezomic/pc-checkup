<?php

declare(strict_types=1);

arch('strict types everywhere')
    ->expect('App')
    ->toUseStrictTypes();

arch('no debugging left behind')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'var_export', 'print_r'])
    ->not->toBeUsed();

arch('no Livewire or Filament')
    ->expect('App')
    ->not->toUse(['Livewire', 'Filament']);

arch('controllers extend the base controller')
    ->expect('App\Http\Controllers')
    ->toExtend('App\Http\Controllers\Controller')
    ->ignoring('App\Http\Controllers\Controller');

arch('concrete controllers are final')
    ->expect('App\Http\Controllers')
    ->classes()
    ->toBeFinal()
    ->ignoring('App\Http\Controllers\Controller');

arch('actions expose a handle method')
    ->expect('App\Actions')
    ->toHaveMethod('handle')
    ->ignoring('App\Actions\Fortify');

arch('actions are final')
    ->expect('App\Actions')
    ->classes()
    ->toBeFinal();

arch('services are final')
    ->expect('App\Services')
    ->classes()
    ->toBeFinal();

arch('policies are final and live in App\Policies')
    ->expect('App\Policies')
    ->classes()
    ->toBeFinal();

arch('models extend Eloquent')
    ->expect('App\Models')
    ->toExtend('Illuminate\Database\Eloquent\Model');

arch('form requests extend the framework request')
    ->expect('App\Http\Requests')
    ->toExtend('Illuminate\Foundation\Http\FormRequest');

arch('form requests are final')
    ->expect('App\Http\Requests')
    ->classes()
    ->toBeFinal();

// The two enum conventions below are plain tests rather than arch presets. The
// template ships no enums, and a Pest arch expectation against an empty
// namespace fails with `Method "toBeBackedEnums" does not exist in string`.
// As tests they pass vacuously here and start biting in the first clone that
// adds an enum, which is the point of putting them in the template at all.
// They avoid app_path() and the File facade so they keep working in tests/Unit,
// which is not bound to the Laravel TestCase.

// Backed, so an enum survives a round trip through the database, a queued job
// payload and an Inertia prop. A pure enum silently cannot.
it('keeps every enum backed', function (): void {
    $unbacked = array_values(array_filter(
        appEnums(),
        fn (string $enum): bool => ! (new ReflectionEnum($enum))->isBacked(),
    ));

    expect($unbacked)->toBe([]);
});

it('declares enums only in App\Enums', function (): void {
    $enumsDirectory = appPath('Enums').DIRECTORY_SEPARATOR;

    $strays = array_values(array_filter(
        appPhpFiles(),
        fn (string $path): bool => declaresEnum($path) && ! str_starts_with($path, $enumsDirectory),
    ));

    expect($strays)->toBe([]);
});

function appPath(string $directory = ''): string
{
    return dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'app'
        .($directory === '' ? '' : DIRECTORY_SEPARATOR.$directory);
}

/**
 * @return list<string>
 */
function appPhpFiles(): array
{
    $files = [];

    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(appPath(), FilesystemIterator::SKIP_DOTS)
    ) as $file) {
        if ($file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}

function declaresEnum(string $path): bool
{
    foreach (token_get_all((string) file_get_contents($path)) as $token) {
        if (is_array($token) && $token[0] === T_ENUM) {
            return true;
        }
    }

    return false;
}

/**
 * @return list<class-string<UnitEnum>>
 */
function appEnums(): array
{
    $prefixLength = strlen(appPath('Enums')) + 1;
    $enums = [];

    foreach (appPhpFiles() as $path) {
        if (! str_starts_with($path, appPath('Enums').DIRECTORY_SEPARATOR)) {
            continue;
        }

        $enum = 'App\Enums\\'.str_replace(DIRECTORY_SEPARATOR, '\\', substr($path, $prefixLength, -4));

        if (enum_exists($enum)) {
            $enums[] = $enum;
        }
    }

    return $enums;
}
