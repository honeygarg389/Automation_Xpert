<?php

namespace App\Console\Commands;

use App\Support\Database\MysqlDefaultsFile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class DbBackupCommand extends Command
{
    protected $signature = 'db:backup {--disk=local : Storage disk to upload the backup to} {--no-upload : Keep backup local only}';

    protected $description = 'Dump the MySQL database and optionally upload to a storage disk.';

    /** mysqldump can legitimately take a while on a large database. */
    private const TIMEOUT_SECONDS = 900;

    public function handle(): int
    {
        $connection = config('database.default');

        if ($connection !== 'mysql') {
            $this->error("db:backup currently only supports MySQL (configured: {$connection}).");

            return self::FAILURE;
        }

        $cfg = config('database.connections.mysql');
        $db = $cfg['database'];

        // Second precision alone is not enough: db:restore takes a safety
        // backup immediately before restoring, and two backups in the same
        // second produced the SAME filename — the second silently overwrote the
        // first on the storage disk. Losing a safety backup to a name collision
        // defeats the point of taking one.
        $timestamp = now()->format('Y_m_d_His').'_'.substr(bin2hex(random_bytes(3)), 0, 6);
        $filename = "{$db}_{$timestamp}.sql.gz";
        $tmpPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.$filename;

        $this->info("Backing up database `{$db}` to {$filename}…");

        try {
            $bytes = $this->dump($cfg, $tmpPath);
        } catch (\Throwable $e) {
            @unlink($tmpPath);
            $this->error('mysqldump failed: '.$e->getMessage());
            $this->line('Check that mysqldump is on PATH and the credentials are correct.');

            return self::FAILURE;
        }

        if ($bytes === 0) {
            @unlink($tmpPath);
            $this->error('mysqldump produced an empty file. Refusing to treat that as a backup.');

            return self::FAILURE;
        }

        // $bytes is the RAW dump size and is used only for the empty check;
        // report the compressed file size, which is what actually landed.
        $sizeMb = round(filesize($tmpPath) / 1_048_576, 2);
        $this->info("Dump created: {$tmpPath} ({$sizeMb} MB)");

        if (! $this->option('no-upload')) {
            $disk = $this->option('disk');
            $this->info("Uploading to disk `{$disk}`…");

            // NOTE: reads the whole dump into memory. Fine at current sizes,
            // not fine on a large production database. Recorded as a follow-up
            // in docs/deployment-safety.md.
            Storage::disk($disk)->put("backups/{$filename}", file_get_contents($tmpPath));
            $this->info('Upload complete: backups/'.$filename);
        }

        @unlink($tmpPath);

        $this->info('✅  Backup finished.');

        return self::SUCCESS;
    }

    /**
     * Run mysqldump and gzip its output, returning the bytes written.
     *
     * SEC-003: the command is an ARGUMENT ARRAY, so no shell parses it and a
     * password containing shell metacharacters cannot inject anything. The
     * password travels in a 0600 defaults file that is deleted in a `finally`,
     * rather than in the environment where `/proc/<pid>/environ` would expose
     * it on Linux. Gzip is done in PHP: a shell pipe would reintroduce a shell.
     */
    private function dump(array $cfg, string $tmpPath): int
    {
        return MysqlDefaultsFile::using($cfg, function (MysqlDefaultsFile $defaults) use ($cfg, $tmpPath): int {
            $process = new Process([
                'mysqldump',
                // Must be the FIRST argument mysqldump sees.
                '--defaults-extra-file='.$defaults->path,
                '--host='.$cfg['host'],
                '--port='.$cfg['port'],
                '--user='.$cfg['username'],
                // A consistent snapshot without locking the whole database.
                '--single-transaction',
                // Previously omitted, so a restore silently lost them.
                '--routines',
                '--triggers',
                // Avoids needing PROCESS privilege on managed MySQL.
                '--no-tablespaces',
                $cfg['database'],
            ]);

            $process->setTimeout(self::TIMEOUT_SECONDS);

            $gz = gzopen($tmpPath, 'wb9');

            if ($gz === false) {
                throw new \RuntimeException("Could not open {$tmpPath} for writing.");
            }

            $bytes = 0;

            try {
                // Stream so the dump is never held in memory in full.
                $process->run(function (string $type, string $buffer) use ($gz, &$bytes): void {
                    if ($type === Process::OUT) {
                        gzwrite($gz, $buffer);
                        $bytes += strlen($buffer);
                    }
                });
            } finally {
                gzclose($gz);
            }

            if (! $process->isSuccessful()) {
                throw new ProcessFailedException($process);
            }

            return $bytes;
        });
    }
}
