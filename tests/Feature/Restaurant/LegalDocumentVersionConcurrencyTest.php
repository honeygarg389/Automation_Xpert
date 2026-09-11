<?php

namespace Tests\Feature\Restaurant;

use App\Modules\Restaurant\Models\LegalDocumentVersion;
use App\Modules\Restaurant\Services\LegalDocumentPublishingService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Task 1 — genuine cross-connection concurrency proof for the single-
 * published invariant.
 *
 * ⚠️ DELIBERATELY DOES NOT USE RefreshDatabase. RefreshDatabase wraps every
 * test method in ONE open transaction on Eloquent's own connection and rolls
 * it back at the end — so a fixture row created via `Model::factory()->create()`
 * is never actually committed. A second, independent PDO connection cannot
 * see it as a normal read, and a LOCKING read (`SELECT ... FOR UPDATE`) on
 * that same still-open, uncommitted row blocks on WHOEVER holds it — which
 * turned out to be the test's own outer transaction, not the second
 * connection under test. That was tried first and failed with
 * "Lock wait timeout exceeded" on connection A's own first lock attempt,
 * before connection B was ever involved — proof the wrapping transaction was
 * the thing being waited on. So this class calls `migrate:fresh` itself and
 * lets fixture writes really commit, then cleans up in tearDown().
 */
class LegalDocumentVersionConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate:fresh');
    }

    protected function tearDown(): void
    {
        Artisan::call('migrate:fresh');
        parent::tearDown();
    }

    /**
     * Part 1 proves the row lock is REAL, using a per-session lock-wait
     * timeout rather than `FOR UPDATE NOWAIT` (whose exact failure timing
     * proved driver-sensitive): while connection A holds `lockForUpdate()`
     * (a real, committed row now that RefreshDatabase's wrapper is out of
     * the way) inside an open transaction, connection B's own plain
     * `FOR UPDATE` on that same row blocks and then throws a lock-wait
     * error — a plain non-locking read would have returned the stale
     * pre-transaction data instead.
     *
     * Part 2 proves the constraint is the backstop for whatever the lock
     * does not catch: once A has committed, B is forced (bypassing the
     * service and the lock entirely) to attempt a raw INSERT of a second
     * published_slot=1 row for the same document_type — rejected by the
     * UNIQUE constraint. This is the "two simultaneously-published
     * versions" case the DB physically cannot allow, independent of any
     * application-level coordination.
     */
    #[Test]
    public function two_versions_cannot_be_simultaneously_published_even_under_concurrent_writers(): void
    {
        $v1 = LegalDocumentVersion::factory()->create([
            'document_type' => LegalDocumentVersion::TYPE_RESTAURANT_DECLARATION,
            'version' => 'v1',
        ]);
        app(LegalDocumentPublishingService::class)->publish($v1);

        $config = config('database.connections.mysql');
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $config['host'], $config['port'], $config['database'], $config['charset']);

        $connA = new \PDO($dsn, $config['username'], $config['password']);
        $connB = new \PDO($dsn, $config['username'], $config['password']);
        $connA->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $connB->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        // A short session-level timeout so Part 1's block resolves in ~1s
        // instead of MySQL's 50s default, and so the failure is a lock-wait
        // error rather than the test hanging.
        $connB->exec('SET SESSION innodb_lock_wait_timeout = 1');

        // ── Part 1: the lock genuinely blocks a concurrent locking reader ──
        $connA->beginTransaction();
        $connA->prepare('SELECT * FROM legal_document_versions WHERE id = ? FOR UPDATE')
            ->execute([$v1->id]);

        $connB->beginTransaction();
        try {
            $connB->prepare('SELECT * FROM legal_document_versions WHERE id = ? FOR UPDATE')
                ->execute([$v1->id]);
            $connB->rollBack();
            $this->fail('Expected connection B to be blocked by connection A\'s row lock.');
        } catch (\PDOException $e) {
            $this->assertMatchesRegularExpression('/lock wait timeout/i', $e->getMessage());
            $connB->rollBack();
        }

        $connA->commit();

        // ── Part 2: bypassing the lock, the UNIQUE constraint is the backstop ──
        $connB->beginTransaction();
        try {
            $connB->prepare(<<<'SQL'
                INSERT INTO legal_document_versions
                    (document_type, version, status, published_slot, content_body, content_sha256, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
            SQL)->execute([
                LegalDocumentVersion::TYPE_RESTAURANT_DECLARATION,
                'v-race',
                LegalDocumentVersion::STATUS_PUBLISHED,
                1,
                'x',
                hash('sha256', 'x'),
            ]);
            $connB->commit();
            $this->fail('Expected the UNIQUE (document_type, published_slot) constraint to reject a second published row.');
        } catch (\PDOException $e) {
            $connB->rollBack();
            $this->assertMatchesRegularExpression('/duplicate|unique/i', $e->getMessage());
        }

        $this->assertSame(1, DB::table('legal_document_versions')
            ->where('document_type', LegalDocumentVersion::TYPE_RESTAURANT_DECLARATION)
            ->where('published_slot', 1)
            ->count());
    }
}
