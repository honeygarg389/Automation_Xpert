<?php

namespace Tests\Feature\Database;

use App\Support\Database\MysqlDefaultsFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The HARD GATE, boxes 1 and 2: db:backup must be safe, and db:restore must
 * exist and work.
 *
 * The test that matters is the ROUND TRIP. A backup you cannot restore is not
 * a backup, so asserting that a file was produced proves nothing on its own —
 * these tests destroy data and bring it back.
 *
 * SAFETY. Everything here runs against `whatsmine_test`, which the existing
 * guardrails already pin: phpunit.xml forces DB_DATABASE with force="true",
 * tests/bootstrap.php aborts the run if the resolved name does not end in
 * `_test`, and TestCase::setUp() re-checks the booted config. The suite already
 * runs migrate:fresh against this database, so dropping and restoring it is
 * routine rather than novel. db:restore additionally refuses a non-`_test`
 * target of its own accord (GUARD 7), so the rule does not depend on the
 * caller.
 */
class BackupRestoreRoundTripTest extends TestCase
{
    /**
     * Deliberately NOT RefreshDatabase.
     *
     * RefreshDatabase wraps each test in a transaction, but `mysqldump` opens
     * its OWN connection and therefore sees only committed rows — so a marker
     * seeded inside the transaction would never reach the backup and the round
     * trip could not work. These tests commit their fixtures and clean them up
     * themselves.
     */
    private array $markers = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        // Without RefreshDatabase nothing migrates for us, and these tests can
        // legitimately leave a table dropped if one fails midway. Ensure the
        // schema is present before each test rather than assuming a previous
        // one left it that way.
        // migrate:fresh, not migrate. These tests legitimately DROP tables, and
        // `migrate` would do nothing to restore one — the migrations table still
        // records it as run. Rebuilding from scratch is the only self-healing
        // option, and it is safe: the _test guardrails pin the target and the
        // suite already runs migrate:fresh against this schema.
        if (! Schema::hasTable(self::MARKER_TABLE)) {
            Artisan::call('migrate:fresh', ['--force' => true]);
        }
    }

    protected function tearDown(): void
    {
        // Committed rows are not rolled back for us.
        if ($this->markers !== []) {
            try {
                DB::table(self::MARKER_TABLE)->whereIn('key', $this->markers)->delete();
            } catch (\Throwable) {
                // The table may be mid-restore in a failed test; not fatal.
            }
        }

        parent::tearDown();
    }

    /** Guard against ever pointing this file at a real database. */
    private function assertTargetIsTestDatabase(): void
    {
        $db = config('database.connections.mysql.database');
        $this->assertStringEndsWith('_test', $db,
            'These tests destroy data and must only ever run against a _test schema.');
    }

    /**
     * `cache` is used as the marker table because it has NO foreign keys and IS
     * created by migrations — a marker row inserts without needing a user,
     * workspace or client to exist first.
     *
     * `system_settings` was the first choice and was wrong: it exists in the
     * working database but is NOT created by any migration, so it is absent
     * from a freshly migrated test schema.
     */
    private const MARKER_TABLE = 'cache';

    private function seedMarker(string $value): void
    {
        $this->markers[] = $value;

        DB::table(self::MARKER_TABLE)->insert([
            'key' => $value,
            'value' => 'round-trip marker',
            'expiration' => now()->addDay()->timestamp,
        ]);
    }

    private function markerCount(string $value): int
    {
        return DB::table(self::MARKER_TABLE)->where('key', $value)->count();
    }

    private function tableCount(): int
    {
        $db = config('database.connections.mysql.database');

        return (int) DB::table('information_schema.TABLES')
            ->where('TABLE_SCHEMA', $db)
            ->count();
    }

    /** Produce a backup on the faked disk and return its local path. */
    private function makeBackup(): string
    {
        $this->artisan('db:backup')->assertSuccessful();

        $files = Storage::disk('local')->files('backups');
        $this->assertNotEmpty($files, 'db:backup produced no archive.');

        $local = sys_get_temp_dir().'/wm_test_'.uniqid().'.sql.gz';
        file_put_contents($local, Storage::disk('local')->get(end($files)));

        return $local;
    }

    // ── THE ROUND TRIP ─────────────────────────────────────────────────────

    #[Test]
    public function a_backup_can_be_restored_and_the_data_comes_back(): void
    {
        $this->assertTargetIsTestDatabase();

        $marker = 'roundtrip_'.uniqid();
        $this->seedMarker($marker);
        $tablesBefore = $this->tableCount();
        $this->assertSame(1, $this->markerCount($marker));

        $archive = $this->makeBackup();

        // DESTROY. Not a soft delete — remove the rows entirely.
        DB::table(self::MARKER_TABLE)->where('key', $marker)->delete();
        $this->assertSame(0, $this->markerCount($marker), 'Fixture failure: the data was not destroyed.');

        $target = config('database.connections.mysql.database');

        $this->artisan('db:restore', ['--file' => $archive, '--force' => true])
            ->expectsQuestion("Type the database name to confirm you want to OVERWRITE it [{$target}]", $target)
            ->assertSuccessful();

        $this->assertSame(1, $this->markerCount($marker),
            'The restore did not bring the data back — the backup is not a backup.');
        $this->assertSame($tablesBefore, $this->tableCount(),
            'The table count changed across the round trip.');

        @unlink($archive);
    }

    #[Test]
    public function a_restore_rebuilds_a_dropped_table(): void
    {
        $this->assertTargetIsTestDatabase();

        $marker = 'droptable_'.uniqid();
        $this->seedMarker($marker);
        $tablesBefore = $this->tableCount();

        $archive = $this->makeBackup();

        DB::statement('DROP TABLE '.self::MARKER_TABLE);
        $this->assertSame($tablesBefore - 1, $this->tableCount(), 'Fixture failure: the table was not dropped.');

        $target = config('database.connections.mysql.database');

        $this->artisan('db:restore', ['--file' => $archive, '--force' => true])
            ->expectsQuestion("Type the database name to confirm you want to OVERWRITE it [{$target}]", $target)
            ->assertSuccessful();

        $this->assertSame($tablesBefore, $this->tableCount(), 'The dropped table was not rebuilt.');
        $this->assertSame(1, $this->markerCount($marker), 'The rebuilt table came back empty.');

        @unlink($archive);
    }

    // ── GUARD 3 — the pre-restore safety backup ────────────────────────────

    #[Test]
    public function a_restore_takes_a_safety_backup_of_the_current_state_first(): void
    {
        $this->assertTargetIsTestDatabase();

        $archive = $this->makeBackup();
        $before = count(Storage::disk('local')->files('backups'));

        $target = config('database.connections.mysql.database');

        $this->artisan('db:restore', ['--file' => $archive, '--force' => true])
            ->expectsQuestion("Type the database name to confirm you want to OVERWRITE it [{$target}]", $target)
            ->expectsOutputToContain('Taking a safety backup')
            ->assertSuccessful();

        $this->assertGreaterThan($before, count(Storage::disk('local')->files('backups')),
            'No safety backup was written before the restore.');

        @unlink($archive);
    }

    // ── GUARD 4 — reject a bad archive BEFORE touching the database ────────

    #[Test]
    public function a_corrupt_archive_is_rejected_before_the_database_is_touched(): void
    {
        $this->assertTargetIsTestDatabase();

        $marker = 'corrupt_'.uniqid();
        $this->seedMarker($marker);

        $bad = sys_get_temp_dir().'/wm_corrupt_'.uniqid().'.sql.gz';
        file_put_contents($bad, 'this is not gzip at all');

        $this->artisan('db:restore', ['--file' => $bad, '--force' => true])
            ->assertFailed();

        // The database must be untouched — no safety backup, no restore.
        $this->assertSame(1, $this->markerCount($marker),
            'A corrupt archive was allowed to affect the database.');
        $this->assertEmpty(Storage::disk('local')->files('backups'),
            'A corrupt archive got as far as taking a safety backup.');

        @unlink($bad);
    }

    #[Test]
    public function an_archive_with_no_tables_is_rejected(): void
    {
        $this->assertTargetIsTestDatabase();

        $empty = sys_get_temp_dir().'/wm_empty_'.uniqid().'.sql.gz';
        file_put_contents($empty, gzencode("-- a comment and nothing else\nSELECT 1;\n"));

        $this->artisan('db:restore', ['--file' => $empty, '--force' => true])
            ->expectsOutputToContain('no CREATE TABLE')
            ->assertFailed();

        @unlink($empty);
    }

    #[Test]
    public function a_missing_archive_is_rejected(): void
    {
        $this->artisan('db:restore', ['--file' => '/tmp/does-not-exist-'.uniqid().'.sql.gz', '--force' => true])
            ->assertFailed();
    }

    // ── GUARD 5 — dry run changes nothing ──────────────────────────────────

    #[Test]
    public function dry_run_reports_and_changes_nothing(): void
    {
        $this->assertTargetIsTestDatabase();

        $marker = 'dryrun_'.uniqid();
        $this->seedMarker($marker);

        $archive = $this->makeBackup();
        $backupsBefore = count(Storage::disk('local')->files('backups'));

        DB::table(self::MARKER_TABLE)->where('key', $marker)->delete();

        $this->artisan('db:restore', ['--file' => $archive, '--dry-run' => true])
            ->expectsOutputToContain('nothing was changed')
            ->assertSuccessful();

        // The deleted row must STILL be gone: a dry run must not restore.
        $this->assertSame(0, $this->markerCount($marker), '--dry-run restored data.');
        $this->assertCount($backupsBefore, Storage::disk('local')->files('backups'),
            '--dry-run took a safety backup, so it did not stop early.');

        @unlink($archive);
    }

    // ── GUARD 7 — refuse a non-test target without --force ─────────────────

    /**
     * GUARD 7 in isolation.
     *
     * The archive must claim to come from `whatsmine` too, otherwise GUARD 4's
     * dump-name check fires FIRST and the test would pass without guard 7 ever
     * running — the same "rejected for the wrong reason" trap as the
     * instagram/whatsapp channel guard in the Inbox tests.
     */
    #[Test]
    public function a_non_test_database_is_refused_without_force(): void
    {
        $fake = sys_get_temp_dir().'/wm_prodshape_'.uniqid().'.sql.gz';
        file_put_contents($fake, gzencode(
            "-- Host: 127.0.0.1    Database: whatsmine\n"
            ."CREATE TABLE `x` (`id` int);\n"
        ));

        config(['database.connections.mysql.database' => 'whatsmine']);

        $this->artisan('db:restore', ['--file' => $fake])
            ->expectsOutputToContain('not a test database')
            ->assertFailed();

        // Nothing was written: the guard fires before the safety backup.
        $this->assertEmpty(Storage::disk('local')->files('backups'),
            'A non-test target got as far as taking a safety backup.');

        @unlink($fake);
    }

    /**
     * The counterpart: GUARD 4 rejects an archive taken from a DIFFERENT
     * database, before the target check is even reached.
     */
    #[Test]
    public function an_archive_from_a_different_database_is_refused(): void
    {
        $this->assertTargetIsTestDatabase();

        $fake = sys_get_temp_dir().'/wm_otherdb_'.uniqid().'.sql.gz';
        file_put_contents($fake, gzencode(
            "-- Host: 127.0.0.1    Database: some_other_database\n"
            ."CREATE TABLE `x` (`id` int);\n"
        ));

        $this->artisan('db:restore', ['--file' => $fake])
            ->expectsOutputToContain('was taken from')
            ->assertFailed();

        @unlink($fake);
    }

    // ── GUARD 2 — the typed name must match ────────────────────────────────

    #[Test]
    public function a_mistyped_database_name_aborts_the_restore(): void
    {
        $this->assertTargetIsTestDatabase();

        $marker = 'mistype_'.uniqid();
        $this->seedMarker($marker);

        $archive = $this->makeBackup();
        $target = config('database.connections.mysql.database');

        DB::table(self::MARKER_TABLE)->where('key', $marker)->delete();

        $this->artisan('db:restore', ['--file' => $archive])
            ->expectsQuestion("Type the database name to confirm you want to OVERWRITE it [{$target}]", 'not-the-right-name')
            ->expectsOutputToContain('Name did not match')
            ->assertFailed();

        // Aborted before the safety backup and before the restore.
        $this->assertSame(0, $this->markerCount($marker), 'A mistyped name still restored the database.');

        @unlink($archive);
    }

    #[Test]
    public function the_correct_typed_name_allows_the_restore_to_proceed(): void
    {
        $this->assertTargetIsTestDatabase();

        $marker = 'typed_'.uniqid();
        $this->seedMarker($marker);

        $archive = $this->makeBackup();
        $target = config('database.connections.mysql.database');

        DB::table(self::MARKER_TABLE)->where('key', $marker)->delete();

        $this->artisan('db:restore', ['--file' => $archive])
            ->expectsQuestion("Type the database name to confirm you want to OVERWRITE it [{$target}]", $target)
            ->assertSuccessful();

        $this->assertSame(1, $this->markerCount($marker),
            'Positive control: the correct name should let the restore through.');

        @unlink($archive);
    }

    // ── SEC-003 — the defaults file ────────────────────────────────────────

    #[Test]
    public function the_defaults_file_is_private_and_deleted_afterwards(): void
    {
        $seen = null;

        MysqlDefaultsFile::using(['password' => 'p@ss "with quotes" and \\ backslash'], function ($f) use (&$seen) {
            $seen = $f->path;

            $this->assertFileExists($f->path);
            $this->assertSame('0600', substr(sprintf('%o', fileperms($f->path)), -4),
                'The defaults file must not be readable by other users.');
            $this->assertStringContainsString('[client]', file_get_contents($f->path));

            return null;
        });

        $this->assertFileDoesNotExist($seen, 'The defaults file outlived the operation.');
    }

    #[Test]
    public function the_defaults_file_is_deleted_even_when_the_operation_throws(): void
    {
        $seen = null;

        try {
            MysqlDefaultsFile::using(['password' => 'x'], function ($f) use (&$seen) {
                $seen = $f->path;
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertNotNull($seen);
        $this->assertFileDoesNotExist($seen,
            'A leftover 0600 file containing the database password is its own bug.');
    }

    #[Test]
    public function a_password_containing_shell_metacharacters_cannot_inject(): void
    {
        // The original defect: exec("MYSQL_PWD={$pass} mysqldump …") with an
        // unescaped password ran whatever the password contained.
        $probe = sys_get_temp_dir().'/wm_injection_'.uniqid();

        config(['database.connections.mysql.password' => 'x; touch '.$probe.'; echo']);

        $this->artisan('db:backup --no-upload');

        $this->assertFileDoesNotExist($probe,
            'SEC-003: a password containing shell metacharacters executed a command.');
    }
}
