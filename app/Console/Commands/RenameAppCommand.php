<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Template\RenameApp;
use Illuminate\Console\Command;
use RuntimeException;

final class RenameAppCommand extends Command
{
    /**
     * --path exists so the command can be exercised against a fixture
     * directory; a clone always runs it from the app root.
     */
    protected $signature = 'app:rename {slug : The project slug, e.g. my-app} {name : The display name, e.g. "My App"} {--path= : App root to rewrite, defaults to this app}';

    protected $description = 'Rename a fresh clone of the template: composer package plus APP_NAME and APP_URL';

    public function handle(RenameApp $action): int
    {
        $slug = (string) $this->argument('slug');
        $name = (string) $this->argument('name');
        $path = (string) ($this->option('path') ?? '');

        try {
            $changed = $action->handle($path === '' ? base_path() : $path, $slug, $name);
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($changed === []) {
            $this->components->info("Already named \"{$name}\". Nothing to change.");

            return self::SUCCESS;
        }

        foreach ($changed as $file) {
            $this->components->twoColumnDetail($file, '<fg=green>rewritten</>');
        }

        $this->components->info("Renamed to \"{$name}\" at {$slug}.test.");

        return self::SUCCESS;
    }
}
