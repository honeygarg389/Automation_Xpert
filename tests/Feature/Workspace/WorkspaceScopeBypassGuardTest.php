<?php

namespace Tests\Feature\Workspace;

use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Phase 0, slice 2. The bypass inventory.
 *
 * `withoutWorkspaceScope('reason: …')` requires a reason by signature, which
 * makes each bypass self-documenting. It does not make them *countable* — and a
 * bypass that nobody counts is a hole that nobody revisits.
 *
 * This greps for BOTH spellings, per CLAUDE.md:
 *
 *   - `withoutWorkspaceScope`  — the sanctioned form
 *   - `withoutGlobalScope`     — the native Eloquent form, which takes NO reason
 *                                and would otherwise be an unlogged way around
 *                                the whole convention
 *
 * Catching only the first would close nothing while looking correct: anyone who
 * did not know about the convention would reach for the framework method, and
 * the inventory would stay reassuringly empty. This codebase has already
 * produced two "one concept, two definitions" bugs found only by accident.
 *
 * There are currently ZERO bypasses in `app/`. Every future one is a deliberate
 * act and must be added here with its justification — which is the point: the
 * cost of adding a bypass includes explaining it to a reviewer.
 *
 * Note this is a TEXT scan, not static analysis. It cannot be defeated by
 * accident, only on purpose (string concatenation, a variable method name). That
 * is the correct threat model: the guard exists to stop a bypass being added
 * without thought, not to stop a determined author.
 */
class WorkspaceScopeBypassGuardTest extends TestCase
{
    /**
     * Every sanctioned bypass, as `relative/path.php` => why.
     *
     * Adding an entry here is the deliberate act. Reviewers should treat a diff
     * that grows this list the way they would treat a new `@SuppressWarnings`.
     *
     * @var array<string, string>
     */
    private const SANCTIONED = [
        // ── Definitions, not uses of them ──
        'app/Models/Concerns/BelongsToWorkspace.php' => 'Defines the sanctioned bypass. The withoutGlobalScope() call here IS the implementation.',
        'app/Support/WorkspaceContext.php' => 'Defines crossTenant(). The flag and its try/finally are the implementation.',
        'app/Modules/Shared/Services/ChannelAccountRouting.php' => 'BUG-019 routing. findForInbound() must see across workspaces because the workspace is the ANSWER it seeks; resolveForAttach() must, because detecting a cross-workspace claim IS seeing across workspaces. One query wide.',
        'app/Providers/BroadcastChannelsServiceProvider.php' => 'Broadcast auth discovers a conversation workspace, then judges it with userCanAccessWorkspace() — a deliberately broader rule than the current workspace. One query wide; authorization still runs.',
        'app/Modules/Broadcasting/Models/UsageMeter.php' => 'current() takes an explicit workspace_id and its own where() IS the boundary. Under the scope a null context would report ZERO usage, which EnforceLimit reads as under-limit — a missing context would grant unlimited quota. Fail-open; bypassed deliberately.',
        'app/Modules/Ecommerce/Services/ContactCapacity.php' => 'remaining() takes an explicit workspace_id and its own where() IS the boundary. Under the scope a null context would count ZERO contacts and report the FULL limit as available — a capacity check granting unbounded headroom. Fail-open; bypassed deliberately.',
        'app/Modules/Entitlements/Services/GaugeReader.php' => 'Gauge counts apply an explicit workspace_id/client_id which IS the boundary. Under the scope a null context would count ZERO and hand the customer their FULL limit as headroom. Fail-open; bypassed deliberately, and from birth so Phase 0 slice 8 cannot convert these counts later.',
        'app/Modules/SmartQr/Services/SmartQrAccess.php' => 'Every method applies an explicit workspace_id which IS the boundary. Inside whereHas there is no ambient context, so the scope would AND 1=0 onto the subquery and the join would match NOTHING — fail-CLOSED, the mirror of UsageMeter::current(). Caught by the canary, not by reasoning.',
        // ── Smart QR slice 3: the admin surface, and it has NO workspace ──
        //
        // Every entry below is the SAME reason and it is worth stating once: a
        // Super Admin request carries no workspace context by definition — the
        // admin belongs to no tenant. The scope fails CLOSED, so leaving it on
        // does not make these queries safer, it makes them return NOTHING:
        // an empty inventory, an empty assignment list, and — worst — a
        // duplicate check that reports every code unheld.
        //
        // The tenant boundary in each case is either an explicit workspace_id
        // stated beside the bypass, or genuinely absent because the question is
        // platform-level.
        'app/Modules/SmartQr/Http/Controllers/Admin/QrInventoryController.php' => 'Admin inventory spans all tenants by design; inside whereHas the scope ANDs 1=0 and every "assigned" filter would return an empty set for codes that ARE assigned. Fail-CLOSED.',
        'app/Modules/SmartQr/Http/Controllers/Admin/QrBatchController.php' => 'Batch listing derives assigned_count (R-12) across all tenants; the scope would report every batch as holding zero assignments.',
        'app/Modules/SmartQr/Http/Controllers/Admin/QrAssignmentController.php' => 'The Super Admin assignments screen spans all tenants; and unassign resolves by uuid, where a scoped bind would 404 on a row that exists and the permission check would never run.',
        'app/Modules/SmartQr/Actions/AssignQrCodesAction.php' => 'The duplicate check asks "is this code held by ANYONE" — inherently cross-tenant, and it is the check standing between one code and two tenants. Scoped, it answers "no" for every code and leaves only the DB unique index.',
        'app/Modules/SmartQr/Services/SmartQrAssignmentValidator.php' => 'Channel lookup states workspace_id explicitly and THAT is the boundary. Scoped, with no admin context, it would reject every channel including the correct one — the same fail-CLOSED shape as SmartQrAccess.',
        'app/Modules/SmartQr/Services/SmartQrRedirectResolver.php' => 'THE THIRD PUBLIC ENTRY POINT. A stranger scanning a sticker has no session and no workspace — the workspace is the ANSWER the token is being resolved into, the same discovery shape as ChannelAccountRouting::findForInbound(). Scoped, the assignment lookup fails closed and EVERY code on earth reports as unassigned. The channel lookup states workspace_id explicitly and that IS the boundary.',
        'app/Modules/SmartQr/Services/SmartQrDeletability.php' => 'Asks "has this code EVER been assigned" to decide whether deleting it would destroy tenant history. Inherently cross-tenant: an admin has no workspace, and a scoped count returns ZERO for every code — which would report every assigned code deletable and cascade away its scan events. Fail-closed turning into fail-DESTRUCTIVE.',
        'app/Modules/SmartQr/Models/SmartQrAssignment.php' => 'channelAccount() answers "which number does this sticker dial" for the admin inventory detail screen, which reads assignments belonging to every tenant. ChannelAccount is workspace-scoped, so with no admin context the relation resolves to NULL rather than erroring — "Destination phone: —" reads as absent data, not as a bug, so nothing would ever report it. Read-only display; the assignment row it hangs off is already the boundary.',
        'app/Modules/SmartQr/Models/SmartQrCode.php' => 'currentAssignment() answers "which tenant holds this code", which is asked precisely when the answer is unknown. Under the scope isAssigned() returned FALSE for genuinely assigned codes — found by an assertion failing in slice 3. Customer-facing uses are bounded by SmartQrAccess::boundedTo().',

        'app/Models/Scopes/WorkspaceScope.php' => 'The scope itself. It reads isCrossTenant() to honour the door; it does not open one.',
        'app/Jobs/Middleware/EstablishesWorkspaceContext.php' => 'THE one job-context bypass: reads a single workspace_id column so a job can establish its own tenant. One query wide. Also routes declared cross-tenant jobs.',

        // ── Cross-tenant BY DESIGN: the schedulers ──
        // Each scans every workspace for due work. A per-tenant context would
        // silently reduce them to one tenant's — which is why they must be
        // counted here rather than merely commented at the call site.
        'app/Modules/Broadcasting/Jobs/LaunchScheduledCampaignsJob.php' => 'Scheduler: finds campaigns due to send across all workspaces.',
        'app/Modules/Social/Jobs/DispatchScheduledPostsJob.php' => 'Scheduler: finds posts due to publish across all workspaces.',
        'app/Modules/Social/Jobs/RefreshSocialTokensJob.php' => 'Scheduler: refreshes expiring OAuth tokens across all workspaces.',

        // ── Cross-tenant BY DESIGN: the inbound webhook jobs (slice 4c) ──
        // A DIFFERENT reason from the schedulers. A scheduler is cross-tenant
        // because it SHOULD see every workspace. These are cross-tenant because
        // they CANNOT KNOW theirs: one payload legitimately carries messages for
        // several tenants, so context is established per MESSAGE inside the
        // driver, where the routing identifier first resolves to a workspace.
        'app/Modules/Whatsapp/Jobs/ProcessInboundMessageJob.php' => 'One payload can carry messages for several WABAs; context is per message in WhatsappDriver.',
        'app/Modules/Inbox/Jobs/ProcessInboundInboxMessageJob.php' => 'One Meta payload can carry events for several pages; webhooks/meta/{token} is a platform-global token, so the job has no tenant to resolve.',

        // ── Cross-tenant BY DESIGN: platform-operator commands (slice 4b) ──
        'app/Console/Commands/WhatsappWebhookRegisterCommand.php' => 'Registers the platform-wide Meta callback and subscribes EVERY workspace\'s WABA; scoping it would leave the rest silently unsubscribed.',
        'app/Console/Commands/MessengerProfileTestCommand.php' => 'Diagnostic: an operator does not know which workspace a broken Messenger connection is in, so discovering the account is the point. Only the discovery is cross-tenant — the rest runs inside for().',
    ];

    /**
     * Every spelling that takes a query out from under the workspace scope.
     * No single one is sufficient — see the class docblock.
     *
     * `crossTenant` was added in slice 4. It is a scope bypass by another name:
     * it suppresses filtering for the duration of a callable. Leaving it out
     * would have let the three schedulers read every tenant's rows without
     * appearing in any inventory — the same hole `withoutGlobalScope` would be.
     */
    private const PATTERNS = ['withoutWorkspaceScope', 'withoutGlobalScope', 'crossTenant'];

    #[Test]
    public function every_workspace_scope_bypass_in_the_application_is_inventoried(): void
    {
        $found = $this->scan();

        $undeclared = array_diff_key($found, self::SANCTIONED);

        $this->assertSame([], $undeclared, implode("\n", [
            '',
            'An unlisted workspace-scope bypass appeared in app/.',
            '',
            ...array_map(
                fn (string $path, array $hits) => "  {$path}\n".implode("\n", array_map(fn ($h) => "      line {$h['line']}: {$h['text']}", $hits)),
                array_keys($undeclared),
                $undeclared
            ),
            '',
            'If the bypass is correct, add it to self::SANCTIONED with a one-line reason.',
            'If it is not, scope the query instead — or use withoutWorkspaceScope(\'reason: …\'),',
            'which at least forces the reason into the call site.',
            '',
            'A bypass nobody counts is a hole nobody revisits.',
            '',
        ]));
    }

    /**
     * A stale entry is worse than none: it grants standing permission to a file
     * that no longer needs it, and the next bypass added to that file inherits
     * the exemption silently.
     */
    #[Test]
    public function the_sanctioned_list_has_no_stale_entries(): void
    {
        $found = $this->scan();

        $stale = array_keys(array_diff_key(self::SANCTIONED, $found));

        $this->assertSame([], $stale,
            'These files are sanctioned to bypass the workspace scope but no longer contain one. '
            .'Remove them — a stale exemption silently covers the next bypass added to that file: '
            .implode(', ', $stale));
    }

    /**
     * Guards the guard. If someone narrows PATTERNS to the sanctioned spelling
     * only, the inventory keeps passing while `withoutGlobalScope` becomes an
     * unlogged way around the entire convention.
     */
    #[Test]
    public function the_guard_watches_the_native_spelling_too_and_not_only_ours(): void
    {
        $this->assertContains('withoutGlobalScope', self::PATTERNS,
            'The native Eloquent spelling must stay in the scan. It takes no reason argument, '
            .'so it is the spelling a bypass would arrive under by default.');

        $this->assertContains('withoutWorkspaceScope', self::PATTERNS);

        $this->assertContains('crossTenant', self::PATTERNS,
            'crossTenant() suppresses the scope for the duration of a callable. It is a bypass '
            .'and must be counted as one, or the schedulers read every tenant unlisted.');
    }

    /**
     * @return array<string, list<array{line:int,text:string}>>
     */
    private function scan(): array
    {
        $results = [];
        $root = base_path();

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $path = str_replace($root.DIRECTORY_SEPARATOR, '', $file->getPathname());
            $lines = file($file->getPathname(), FILE_IGNORE_NEW_LINES);

            foreach ($lines as $i => $line) {
                foreach (self::PATTERNS as $pattern) {
                    if (str_contains($line, $pattern)) {
                        $results[$path][] = ['line' => $i + 1, 'text' => trim($line)];

                        continue 2;
                    }
                }
            }
        }

        return $results;
    }
}
