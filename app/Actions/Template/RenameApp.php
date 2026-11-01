<?php

declare(strict_types=1);

namespace App\Actions\Template;

use RuntimeException;

/**
 * Rewrites the template's own identity onto a fresh clone: the composer package
 * name and description, plus APP_NAME and APP_URL in the env files.
 *
 * Every rewrite is guarded on the value still being the template's default (or
 * already the target, so a second run is a no-op). Without that guard this is a
 * command that quietly renames a live app.
 */
final class RenameApp
{
    private const string TEMPLATE_PACKAGE = 'thijssensoftware/web-template';

    private const string TEMPLATE_APP_NAME = 'Web Template';

    private const string VENDOR = 'thijssensoftware';

    /**
     * @return list<string> the files that were changed, relative to the base path
     */
    public function handle(string $basePath, string $slug, string $name): array
    {
        if (preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug) !== 1) {
            throw new RuntimeException("\"{$slug}\" is not a valid slug: use lowercase letters, digits and single hyphens.");
        }

        return array_values(array_filter([
            $this->rewriteComposer($basePath, $slug, $name),
            ...array_map(
                fn (string $file): ?string => $this->rewriteEnv($basePath, $file, $slug, $name),
                ['.env.example', '.env'],
            ),
        ]));
    }

    private function rewriteComposer(string $basePath, string $slug, string $name): ?string
    {
        $path = $basePath.'/composer.json';

        if (! is_file($path)) {
            throw new RuntimeException('composer.json not found: is this the app root?');
        }

        $contents = (string) file_get_contents($path);
        $target = self::VENDOR.'/'.$slug;

        if (preg_match('/"name":\s*"([^"]*)"/', $contents, $matches) !== 1) {
            throw new RuntimeException('composer.json has no "name" to rewrite.');
        }

        $current = $matches[1];

        if ($current === $target) {
            return null;
        }

        if ($current !== self::TEMPLATE_PACKAGE) {
            throw new RuntimeException("composer.json is already named \"{$current}\", not the template default. Refusing to rename what looks like a live app.");
        }

        $rewritten = preg_replace(
            ['/"name":\s*"[^"]*"/', '/"description":\s*"[^"]*"/'],
            [sprintf('"name": "%s"', $target), sprintf('"description": "%s web application."', $name)],
            $contents,
            1,
        );

        file_put_contents($path, (string) $rewritten);

        return 'composer.json';
    }

    private function rewriteEnv(string $basePath, string $file, string $slug, string $name): ?string
    {
        $path = $basePath.'/'.$file;

        // .env is absent until someone copies the example, so this is a skip
        // rather than a failure.
        if (! is_file($path)) {
            return null;
        }

        $contents = (string) file_get_contents($path);

        if (preg_match('/^APP_NAME=(.*)$/m', $contents, $matches) !== 1) {
            throw new RuntimeException("{$file} has no APP_NAME to rewrite.");
        }

        $current = trim($matches[1], "\"' \t");

        if ($current !== self::TEMPLATE_APP_NAME && $current !== $name) {
            throw new RuntimeException("{$file} is already named \"{$current}\", not the template default. Refusing to rename what looks like a live app.");
        }

        $rewritten = preg_replace(
            ['/^APP_NAME=.*$/m', '/^APP_URL=.*$/m'],
            [sprintf('APP_NAME="%s"', $name), sprintf('APP_URL=http://%s.test', $slug)],
            $contents,
        );

        if ($rewritten === $contents) {
            return null;
        }

        file_put_contents($path, (string) $rewritten);

        return $file;
    }
}
