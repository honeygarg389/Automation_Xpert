<?php

namespace App\Console\Commands;

use App\Support\Database\MysqlDefaultsFile;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Restore a `db:backup` archive into the configured database.
 *
 * This is the most dangerous command in the codebase: it overwrites a database.
 * Every guard below exists because a restore that goes wrong with no way back
 * is indistinguishable from data loss. They are numbered to match the design
 * agreed before implementation.
 */
class DbRestoreCommand extends Command
{
    use ConfirmableTrait;

    protected $signature = 'db:restore
        {--file= : Path to a .sql.gz backup, or a key on the storage disk}
        {--disk=local : Storage disk to read --file from when it is not a local path}
        {--dry-run : Report what would happen and touch nothing}
        {--force : Bypass the production and non-test-database refusals}';

    protected $description = 'Restore a database backup. Overwrites the target database.';

    private const TIMEOUT_SECONDS = 1800;

    public function handle(): int
    {
        $connection = config('database.default');

        if ($connection !== 'mysql') {
            $this->error("db:restore currently only supports MySQL (configured: {$connection}).");

            return self::FAILURE;
        }

        $cfg = config('database.connections.mysql');

        // GUARD 6 — never guess the target. There is deliberately no
        // --database option: the target is always the configured connection,
        // so a typo cannot point this at something else.
        $target = $cfg['database'];

        $archive = $this->resolveArchive();

        if ($archive === null) {
            return self::FAILURE;
        }

        // GUARD 4 — verify the archive BEFORE touching the database. A corrupt
        // or truncated file must be rejected while the database is still
        // intact, not halfway through a restore.
        $inspection = $this->inspectArchive($archive, $target);

        if ($inspection === null) {
            return self::FAILURE;
        }

        $this->line('');
        $this->line('  Target database : <options=bold>'.$target.'</>');
        $this->line('  Archive         : '.$archive);
        $this->line('  Tables in dump  : '.$inspection['tables']);
        $this->line('  Dump size       : '.round(filesize($archive) / 1_048_576, 2).' MB');
        $this->line('');

        // GUARD 5 — dry run reports and stops.
        if ($this->option('dry-run')) {
            $this->info('--dry-run: nothing was changed.');

            return self::SUCCESS;
        }

        // GUARD 1 — refuse in production unless forced and confirmed.
        // ConfirmableTrait is the framework's own pattern for this.
        if (! $this->confirmToProceed('Restoring will OVERWRITE the '.$target.' database')) {
            return self::FAILURE;
        }

        // GUARD 7 — refuse a target that is not a test database unless forced.
        // The bootstrap guardrail protects the test suite; this puts the same
        // rule inside the command, so it does not depend on the caller
        // remembering it.
        if (! str_ends_with($target, '_test') && ! $this->option('force')) {
            $this->error("Refusing to restore into `{$target}`: it is not a test database.");
            $this->line('Re-run with --force if you genuinely intend to overwrite it.');

            return self::FAILURE;
        }

        // GUARD 2 — make the operator type the database name. A y/n prompt is
        // muscle memory; typing the name is a deliberate act.
        if (! $this->confirmTargetByName($target)) {
            return self::FAILURE;
        }

        // GUARD 3 — take a safety backup FIRST, and refuse to continue if it
        // fails. This is what makes a mistake recoverable.
        if (! $this->takeSafetyBackup()) {
            return self::FAILURE;
        }

        $this->info("Restoring `{$target}`…");

        try {
            $this->runRestore($cfg, $archive);
        } catch (\Throwable $e) {
            $this->error('Restore failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('✅  Restore finished.');

        return self::SUCCESS;
    }

    /** Resolve --file to a readable local path, pulling from the disk if needed. */
    private function resolveArchive(): ?string
    {
        $file = (string) $this->option('file');

        if ($file === '') {
            $this->error('--file is required.');

            return null;
        }

        if (is_file($file)) {
            return $file;
        }

        $disk = $this->option('disk');

        if (\Illuminate\Support\Facades\Storage::disk($disk)->exists($file)) {
            $tmp = tempnam(sys_get_temp_dir(), 'wm_restore_').'.sql.gz';
            file_put_contents($tmp, \Illuminate\Support\Facades\Storage::disk($disk)->get($file));

            return $tmp;
        }

        $this->error("Backup not found: {$file} (checked the filesystem and disk `{$disk}`).");

        return null;
    }

    /**
     * GUARD 4. Confirm the archive is readable gzip containing a real dump, and
     * that it was taken from this database.
     *
     * @return array{tables:int}|null
     */
    private function inspectArchive(string $path, string $target): ?array
    {
        $gz = @gzopen($path, 'rb');

        if ($gz === false) {
            $this->error('Could not open the archive.');

            return null;
        }

        $tables = 0;
        $sawDump = false;
        $dumpDatabase = null;

        try {
            while (! gzeof($gz)) {
                $line = gzgets($gz);

                if ($line === false) {
                    // Truncated mid-stream: gzip could not decode to the end.
                    $this->error('The archive is corrupt or truncated. Refusing to restore from it.');

                    return null;
                }

                if (str_starts_with($line, 'CREATE TABLE')) {
                    $tables++;
                    $sawDump = true;
                }

                if ($dumpDatabase === null && preg_match('/^-- Host:.*Database:\s*(\S+)/', $line, $m)) {
                    $dumpDatabase = $m[1];
                }
            }
        } finally {
            gzclose($gz);
        }

        if (! $sawDump || $tables === 0) {
            $this->error('The archive contains no CREATE TABLE statements. It is not a database dump.');

            return null;
        }

        // A dump taken from a different database is usually a mistake. Allow it
        // explicitly rather than silently — restoring production into test, or
        // the reverse, should be a decision.
        if ($dumpDatabase !== null && $dumpDatabase !== $target && ! $this->option('force')) {
            $this->error("The archive was taken from `{$dumpDatabase}` but the target is `{$target}`.");
            $this->line('Re-run with --force if that is intentional.');

            return null;
        }

        return ['tables' => $tables];
    }

    /** GUARD 2. */
    private function confirmTargetByName(string $target): bool
    {
        if ($this->option('force') && ! $this->input->isInteractive()) {
            return true;
        }

        $typed = (string) $this->ask("Type the database name to confirm you want to OVERWRITE it [{$target}]");

        if ($typed !== $target) {
            $this->error('Name did not match. Aborted; nothing was changed.');

            return false;
        }

        return true;
    }

    /** GUARD 3. */
    private function takeSafetyBackup(): bool
    {
        $this->info('Taking a safety backup of the current database first…');

        $exit = $this->call('db:backup');

        if ($exit !== self::SUCCESS) {
            $this->error('The safety backup FAILED. Refusing to restore — there would be no way back.');

            return false;
        }

        $this->info('Safety backup complete. Continuing.');

        return true;
    }

    /**
     * Feed the decompressed dump to `mysql` on stdin.
     *
     * Same SEC-003 protections as db:backup: an argument array so no shell
     * parses anything, and the password in a 0600 defaults file deleted in a
     * `finally` rather than in the environment.
     */
    private function runRestore(array $cfg, string $archive): void
    {
        MysqlDefaultsFile::using($cfg, function (MysqlDefaultsFile $defaults) use ($cfg, $archive): void {
            $gz = gzopen($archive, 'rb');

            if ($gz === false) {
                throw new \RuntimeException('Could not read the archive.');
            }

            $process = new Process([
                'mysql',
                '--defaults-extra-file='.$defaults->path,
                '--host='.$cfg['host'],
                '--port='.$cfg['port'],
                '--user='.$cfg['username'],
                $cfg['database'],
            ]);

            $process->setTimeout(self::TIMEOUT_SECONDS);

            // Stream the decompressed dump in rather than materialising it.
            $process->setInput((function () use ($gz) {
                try {
                    while (! gzeof($gz)) {
                        $chunk = gzread($gz, 1_048_576);

                        if ($chunk === false) {
                            throw new \RuntimeException('The archive became unreadable mid-restore.');
                        }

                        yield $chunk;
                    }
                } finally {
                    gzclose($gz);
                }
            })());

            $process->run();

            if (! $process->isSuccessful()) {
                throw new ProcessFailedException($process);
            }

            DB::reconnect();
        });
    }
}
