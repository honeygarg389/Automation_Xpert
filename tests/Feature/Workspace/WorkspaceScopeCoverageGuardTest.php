<?php

namespace Tests\Feature\Workspace;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Phase 0, slice 2. The coverage guard: no model whose table has a
 * `workspace_id` column may go unscoped without appearing in an inventory.
 *
 * ─── Why an allow-list and not a red test ───────────────────────────────────
 *
 * The obvious shape is "fail until all 27 models are scoped", which starts red
 * and burns down. It was rejected. A red guard cannot live in a green suite, and
 * every way of parking it — `markTestSkipped`, `markTestIncomplete`, an
 * `@group` excluded from the run — produces the same outcome: a guard that is
 * silently off. `docs/test-suite-baseline.md` now treats a skip count above 1 as
 * a regression precisely so that disabled tests cannot hide, and this guard must
 * not be the first exception to a rule written days ago.
 *
 * So the burn-down lives in {@see self::PENDING} instead. The guard is GREEN
 * today and stays green, while being impossible to satisfy by accident:
 *
 *   - a NEW model on a `workspace_id` table that is neither scoped nor listed
 *     fails immediately — this is the case that matters most, because it is the
 *     one nobody will be thinking about in six months;
 *   - applying the trait WITHOUT deleting the entry fails, so each slice must
 *     edit this list and the burn-down appears in the diff;
 *   - a stale entry for a model that no longer exists fails.
 *
 * The trade-off, stated plainly: this does not force the work to happen. It
 * forces the work to be *visible*, and it forces anything new to be a deliberate
 * addition to a list rather than a silent omission. `PENDING` shrinking to
 * `[]` is the completion signal for Phase 0.
 */
class WorkspaceScopeCoverageGuardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ═══ THE PHASE 0 WORK QUEUE ═══
     *
     * Models whose table has `workspace_id` and which are NOT YET scoped.
     * Delete an entry in the same commit that applies the trait to it.
     *
     * When this array is empty, Phase 0's model migration is done.
     *
     * @var list<class-string<Model>>
     */
    private const PENDING = [
        // ═══ slice 6 — Contact and the Shared models (the hard case) ═══
        //
        // ⚠️ TWO OF THESE HAVE PREREQUISITES. Applying the trait without doing
        // the work below does not produce an error — it produces a SILENTLY
        // EMPTY result on paths that carry customer messages. Read both notes
        // before touching this group.
        //
        // ── ChannelAccount — DONE at slice 7 ────────────────────────────────
        //
        // Its prerequisite was closed in slice 6, before the trait went on.
        // Kept below because it is the reasoning, not a to-do.
        //
        // `App\Modules\Shared\Services\ChannelAccountRouting` (added by
        // BUG-019) queries ChannelAccount with Eloquent in BOTH its methods, and
        // both need a ONE-QUERY bypass the moment the trait lands:
        //
        //   findForInbound()    routes every inbound WhatsApp/Messenger/Instagram
        //                       message. It runs with no authenticated user and
        //                       the workspace is the ANSWER it is looking for.
        //                       Scoped, it returns null for EVERY message and
        //                       each driver's "no channel_account match" branch
        //                       silently drops the lot. Hazard H-3.
        //
        //   resolveForAttach()  detects a routing identifier already claimed by
        //                       ANOTHER workspace. Seeing across workspaces is
        //                       the entire point; scoped, it sees nothing,
        //                       refuses nothing, and BUG-019 quietly returns.
        //
        // You must ALSO add that service to WorkspaceScopeBypassGuardTest's
        // SANCTIONED list, or the bypass inventory goes red.
        //
        // ── Contact — DONE at slice 6 ───────────────────────────────────────
        //
        // Its prerequisite (AutomationWebhookController's unauthenticated
        // contact resolution) was closed first, in the same slice: the
        // controller now wraps both lookups in WorkspaceContext::for() using the
        // automation's own workspace. No bypass was needed — trigger_token is
        // unique, so the automation identifies its tenant.
        //
        // ────────────────────────────────────────────────────────────────────

        // ── slices 7-8 — the remaining modules ──
        'App\Modules\Leads\Models\LeadScrapeJob',
        'App\Modules\Broadcasting\Models\Campaign',
        'App\Modules\Broadcasting\Models\SmsProviderConfig',
        'App\Modules\Broadcasting\Models\WorkspaceSmtpConfig',
        'App\Modules\Broadcasting\Models\UsageMeter',
        'App\Modules\Inbox\Models\CannedReply',
        'App\Modules\Inbox\Models\InboxLabel',
        'App\Modules\Ecommerce\Models\EcommerceCart',
        'App\Modules\Ecommerce\Models\EcommerceProduct',
        'App\Modules\Ecommerce\Models\EcommerceStore',
        'App\Modules\Ecommerce\Models\EcommerceOrder',
        'App\Modules\Social\Models\SocialPost',
        'App\Modules\Social\Models\SocialAccount',
        'App\Modules\AI\Models\AiKnowledgeBase',
        'App\Modules\AI\Models\AiProviderConfig',
        'App\Modules\AI\Models\AiChatbot',
        'App\Modules\Automation\Models\Automation',
        'App\Modules\Whatsapp\Models\WhatsappWidget',
        'App\Modules\Whatsapp\Models\WhatsappBusinessAccount',
        'App\Modules\Whatsapp\Models\WhatsappTemplate',
        'App\Modules\Whatsapp\Models\WhatsappAutoReply',
    ];

    /**
     * Models on a `workspace_id` table that must NEVER be scoped.
     *
     * `User` is infrastructure: the scope fails closed, and a login lookup
     * happens before anyone is authenticated, so scoping it locks every account
     * out of the application. Measured — see WorkspaceScopeTest.
     *
     * ─── THE STANDARD FOR ADDING TO THIS LIST ───────────────────────────────
     *
     * ⚠️ This guard matches on a COLUMN NAME, not on ownership. It asks "does
     * this table have a workspace_id?" — which is a proxy for "is this customer
     * data", and a proxy that will eventually be wrong.
     *
     * A PLATFORM-OWNED table can legitimately carry a `workspace_id` meaning
     * something other than tenancy: "the workspace this platform record refers
     * to", "the workspace that owns the thing being audited", "the workspace a
     * platform-level job is acting upon". In every one of those the column is a
     * REFERENCE, not an OWNER, and scoping the model would filter platform data
     * by a customer boundary it does not belong to.
     *
     * So the test to apply before adding an entry here is NOT "does scoping it
     * break something" — plenty of correct scoping breaks something. It is:
     *
     *     Does a row of this table BELONG TO the workspace named in that
     *     column, such that a user of another workspace must never see it?
     *
     *   YES  -> it is customer data. Scope it. Fix whatever breaks.
     *   NO   -> the column is a reference. Add it here WITH THE REASON, in the
     *           shape of User's above: what the column actually means, and what
     *           scoping it would break, measured rather than predicted.
     *
     * Two worked examples of the "NO" side, neither of which reaches this list
     * because neither table has the column: `Partner` and `Client` are
     * platform/reseller-level and sit ABOVE the tenant boundary entirely.
     * `PartnerTierTest` asserts they are unscoped for exactly that reason — and
     * it has to, because a table with no `workspace_id` never enters this
     * guard's inventory at all. **This guard cannot see the models most likely
     * to be wrongly scoped.**
     *
     * @var list<class-string<Model>>
     */
    private const NEVER_SCOPED = [
        'App\Models\User',
    ];

    // ── The guard ──────────────────────────────────────────────────────────

    #[Test]
    public function every_workspace_owned_model_is_scoped_or_on_the_pending_list(): void
    {
        $unaccounted = [];

        foreach ($this->modelsOnWorkspaceTables() as $class => $table) {
            if (in_array($class, self::NEVER_SCOPED, true)) {
                continue;
            }
            if ($this->isScoped($class)) {
                continue;
            }
            if (in_array($class, self::PENDING, true)) {
                continue;
            }

            $unaccounted[] = "  {$class}  (table: {$table})";
        }

        $this->assertSame([], $unaccounted, implode("\n", [
            '',
            'A model sits on a table with a `workspace_id` column but is neither scoped nor',
            'declared as pending. Customer data on that table is unprotected and nothing says so.',
            '',
            'Unaccounted for:',
            implode("\n", $unaccounted),
            '',
            'Do ONE of:',
            '  1. Apply App\Models\Concerns\BelongsToWorkspace  (preferred)',
            '  2. Add it to self::PENDING with the slice it belongs to',
            '  3. Add it to self::NEVER_SCOPED with the reason, if it is infrastructure',
            '',
            'Do not delete this test.',
            '',
        ]));
    }

    /**
     * The burn-down must be honest: an entry that is already scoped is a stale
     * work queue, and a work queue nobody trusts is a work queue nobody reads.
     */
    #[Test]
    public function the_pending_list_contains_nothing_that_is_already_scoped(): void
    {
        $done = array_values(array_filter(self::PENDING, fn (string $c) => class_exists($c) && $this->isScoped($c)));

        $this->assertSame([], $done, implode("\n", [
            '',
            'These models are scoped but still listed as PENDING. Delete them from the list in',
            'the same commit that applies the trait — the shrinking list IS the progress report.',
            '',
            implode("\n", array_map(fn ($c) => "  {$c}", $done)),
            '',
        ]));
    }

    #[Test]
    public function the_pending_list_contains_no_models_that_have_stopped_existing(): void
    {
        $missing = array_values(array_filter(
            [...self::PENDING, ...self::NEVER_SCOPED],
            fn (string $c) => ! class_exists($c)
        ));

        $this->assertSame([], $missing,
            'PENDING/NEVER_SCOPED name classes that do not exist: '.implode(', ', $missing));
    }

    /**
     * The work queue, asserted against reality. If a `workspace_id` table is
     * added and its model is never listed, the first test catches it — but this
     * one states the remaining count outright so the number appears in the
     * failure output of any slice that forgets to update the list.
     */
    #[Test]
    public function the_phase_zero_burn_down_is_where_the_list_says_it_is(): void
    {
        $pending = count(self::PENDING);
        $scoped = count(array_filter(
            array_keys($this->modelsOnWorkspaceTables()),
            fn (string $c) => $this->isScoped($c)
        ));

        $this->assertSame(
            $pending + $scoped + count(self::NEVER_SCOPED),
            count($this->modelsOnWorkspaceTables()),
            "Phase 0 burn-down: {$scoped} scoped, {$pending} pending. Every model on a "
            .'workspace_id table must fall into exactly one of scoped / pending / never-scoped.'
        );
    }

    // ── Discovery ──────────────────────────────────────────────────────────

    /** @return array<class-string<Model>, string> class => table */
    private function modelsOnWorkspaceTables(): array
    {
        static $cache = null;

        if ($cache !== null) {
            return $cache;
        }

        $tables = array_map(
            fn ($r) => $r->t,
            DB::select("SELECT DISTINCT TABLE_NAME t FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'workspace_id'")
        );

        $found = [];

        foreach ($this->declaredModels() as $class) {
            $table = (new $class)->getTable();

            if (in_array($table, $tables, true)) {
                $found[$class] = $table;
            }
        }

        return $cache = $found;
    }

    /** @return list<class-string<Model>> */
    private function declaredModels(): array
    {
        $models = [];

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());

            if (! preg_match('/^namespace\s+([^;]+);/m', $source, $ns)) {
                continue;
            }
            if (! preg_match('/^(?:final\s+)?(?:abstract\s+)?class\s+(\w+)/m', $source, $cn)) {
                continue;
            }

            $class = $ns[1].'\\'.$cn[1];

            if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
                continue;
            }

            $reflection = new \ReflectionClass($class);

            if ($reflection->isAbstract()) {
                continue;
            }

            $models[] = $class;
        }

        return $models;
    }

    private function isScoped(string $class): bool
    {
        return in_array(BelongsToWorkspace::class, class_uses_recursive($class), true);
    }
}
