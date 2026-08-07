<?php

namespace App\Support\Database;

use RuntimeException;

/**
 * A short-lived 0600 defaults file carrying the MySQL password.
 *
 * SEC-003. The password must reach `mysqldump`/`mysql` without ever being
 * parsed by a shell and without sitting in the child process's environment:
 *
 *  - NOT interpolated into a command string. That was the original defect and
 *    it is a command-injection vector, not a theoretical one: a password of
 *    `x; touch /tmp/pwned; echo` executed the injected command. Argument arrays
 *    remove the shell entirely, so nothing can be injected.
 *  - NOT `MYSQL_PWD` in the environment. That keeps it out of argv, but the
 *    value is then readable from `/proc/<pid>/environ` by the same user or root
 *    on Linux — which is what production runs on — and via `ps -E` locally.
 *
 * `--defaults-extra-file` is the remaining option MySQL itself documents for
 * this. The file exists for the lifetime of one process, is chmod 0600 before
 * the password is written to it, and is deleted in a `finally` — a leftover
 * 0600 file containing the database password would be its own security bug.
 */
final class MysqlDefaultsFile
{
    private function __construct(public readonly string $path) {}

    /**
     * Run $callback with a defaults file, deleting it afterwards no matter what.
     *
     * @template T
     * @param  callable(self): T  $callback
     * @return T
     */
    public static function using(array $config, callable $callback): mixed
    {
        $file = self::create($config);

        try {
            return $callback($file);
        } finally {
            $file->delete();
        }
    }

    private static function create(array $config): self
    {
        $path = tempnam(sys_get_temp_dir(), 'wm_mysql_');

        if ($path === false) {
            throw new RuntimeException('Could not create a temporary defaults file.');
        }

        // Restrict BEFORE writing the password: between creation and chmod the
        // file is world-readable by default on some systems.
        if (! chmod($path, 0600)) {
            @unlink($path);
            throw new RuntimeException('Could not restrict permissions on the defaults file.');
        }

        $ini = "[client]\n"
            .'password="'.self::escapeIni((string) ($config['password'] ?? ''))."\"\n";

        if (file_put_contents($path, $ini) === false) {
            @unlink($path);
            throw new RuntimeException('Could not write the defaults file.');
        }

        return new self($path);
    }

    /**
     * my.cnf quoting: backslash is the escape character inside a double-quoted
     * value, so backslashes and quotes must be doubled up.
     */
    private static function escapeIni(string $value): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    }

    public function delete(): void
    {
        if (is_file($this->path)) {
            @unlink($this->path);
        }
    }
}
