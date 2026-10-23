<?php

namespace App\Support;

/**
 * The version shown on login screens, side menus and the site footer: the release number from the
 * VERSION file (bumped with each release note) plus the git commit the server is on.
 * Both are read once into config (config/app.php), so on the server they are fixed by deploy step 8's
 * config:cache, right after the pull. Reads .git directly: no shell, nothing breaks when .git is missing.
 */
class AppVersion
{
    public static function release(): string
    {
        $file = base_path('VERSION');

        return is_readable($file) ? (trim((string) file_get_contents($file)) ?: 'dev') : 'dev';
    }

    /** Short hash of the checked-out commit, or '' when it cannot be read. */
    public static function commit(): string
    {
        $git = base_path('.git');
        $head = is_readable($git.'/HEAD') ? trim((string) file_get_contents($git.'/HEAD')) : '';

        if (! str_starts_with($head, 'ref: ')) {
            return self::short($head);
        }

        $ref = substr($head, 5);
        if (is_readable($git.'/'.$ref)) {
            return self::short(trim((string) file_get_contents($git.'/'.$ref)));
        }

        // After git gc the branch tip lives in packed-refs as "<hash> <ref>".
        foreach (is_readable($git.'/packed-refs') ? file($git.'/packed-refs', FILE_IGNORE_NEW_LINES) : [] as $line) {
            if (str_ends_with($line, ' '.$ref)) {
                return self::short(strtok($line, ' '));
            }
        }

        return '';
    }

    /** "v2026.10.23 · 5445a80" (commit left out when unknown). */
    public static function label(): string
    {
        $build = (string) config('app.build');

        return 'v'.config('app.version').($build !== '' ? ' · '.$build : '');
    }

    private static function short(string $hash): string
    {
        return preg_match('/^[0-9a-f]{40}$/', $hash) ? substr($hash, 0, 7) : '';
    }
}
