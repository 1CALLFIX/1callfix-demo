<?php

/*
 * REF 1CF-PROMPT-20260925-EARN4 (1.2) — first step of `composer setup`.
 *
 * `composer setup` is a LOCAL bootstrap: it runs `composer install` WITH dev
 * packages, `migrate --force` and an npm build. On the production server it
 * would install dev tooling and migrate without the runbook's maintenance
 * window. This guard stops the whole script chain (non-zero exit) when the
 * environment is production — read from the real environment first, then
 * from .env if one exists. No Laravel bootstrap: it must work before
 * `composer install` has ever run.
 */

$env = getenv('APP_ENV');

if ($env === false || $env === '') {
    $dotEnv = __DIR__.'/../.env';
    if (is_file($dotEnv) && preg_match('/^\s*APP_ENV\s*=\s*"?([^"\r\n#]*)"?/m', (string) file_get_contents($dotEnv), $m)) {
        $env = trim($m[1]);
    }
}

if (strtolower(trim((string) $env)) === 'production') {
    fwrite(STDERR, "Refusing to run `composer setup`: APP_ENV=production.\n"
        ."`composer setup` is a local bootstrap (dev packages, migrate --force, npm build).\n"
        ."Deploy with docs/DEPLOYMENT_RUNBOOK.md instead (composer install --no-dev --optimize-autoloader).\n");
    exit(1);
}

exit(0);
