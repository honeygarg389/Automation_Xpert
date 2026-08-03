<?php

/**
 * PHPUnit bootstrap — test database guardrail.
 *
 * The suite uses RefreshDatabase (migrate:fresh) in 79 of 81 test files, which
 * DROPS EVERY TABLE in the connected schema. phpunit.xml previously left
 * DB_CONNECTION/DB_DATABASE unset, so the suite inherited the connection from
 * .env and would have destroyed the working database.
 *
 * This file runs before any test is instantiated and aborts the entire run if
 * the resolved schema is not an explicit test schema. It is deliberately plain
 * PHP with no framework dependency so that nothing can boot ahead of it.
 */
require __DIR__.'/../vendor/autoload.php';

(static function (): void {
    $required = '_test';

    /** Read an env var the way PHPUnit exposes it (getenv + superglobals). */
    $read = static function (string $key): ?string {
        foreach ([getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null] as $v) {
            if (is_string($v) && $v !== '') {
                return $v;
            }
        }

        return null;
    };

    $connection = $read('DB_CONNECTION');
    $database = $read('DB_DATABASE');

    // If phpunit.xml did not set them, Laravel will fall through to .env — which
    // points at the working database. Resolve the same fallback here so the
    // guardrail sees exactly what the framework would.
    $fallbackSource = 'phpunit.xml';

    if ($database === null || $connection === null) {
        $fallbackSource = '.env (phpunit.xml did not set it)';
        $envPath = __DIR__.'/../.env';

        if (is_file($envPath)) {
            foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
                    continue;
                }
                [$k, $v] = explode('=', $line, 2);
                $k = trim($k);
                $v = trim(trim($v), "\"'");
                if ($k === 'DB_DATABASE' && $database === null) {
                    $database = $v;
                }
                if ($k === 'DB_CONNECTION' && $connection === null) {
                    $connection = $v;
                }
            }
        }
    }

    $abort = static function (string $why) use ($connection, $database, $fallbackSource): void {
        $line = str_repeat('=', 78);
        fwrite(STDERR, PHP_EOL.$line.PHP_EOL);
        fwrite(STDERR, "  ABORTED — REFUSING TO RUN THE TEST SUITE".PHP_EOL);
        fwrite(STDERR, $line.PHP_EOL);
        fwrite(STDERR, "  {$why}".PHP_EOL.PHP_EOL);
        fwrite(STDERR, '  Resolved connection : '.($connection ?? '(unset)').PHP_EOL);
        fwrite(STDERR, '  Resolved database   : '.($database ?? '(unset)').PHP_EOL);
        fwrite(STDERR, '  Resolved from       : '.$fallbackSource.PHP_EOL.PHP_EOL);
        fwrite(STDERR, '  The suite uses RefreshDatabase, which runs migrate:fresh and'.PHP_EOL);
        fwrite(STDERR, '  DROPS EVERY TABLE in the connected schema.'.PHP_EOL.PHP_EOL);
        fwrite(STDERR, '  Fix: set DB_DATABASE in phpunit.xml to a schema whose name ends'.PHP_EOL);
        fwrite(STDERR, "       in '_test' (e.g. whatsmine_test). Do not edit .env.".PHP_EOL);
        fwrite(STDERR, $line.PHP_EOL.PHP_EOL);
        exit(1);
    };

    if ($database === null || $database === '') {
        $abort('No database name could be resolved.');
    }

    if (! str_ends_with($database, $required)) {
        $abort("Database '{$database}' is not a test schema (must end in '{$required}').");
    }

    if ($connection === 'sqlite' || $database === ':memory:') {
        $abort('SQLite is not supported: the schema relies on MySQL JSON columns and MySQL-specific migrations.');
    }
})();
