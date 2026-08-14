<?php

namespace Tests\Feature\SmartQr;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ⚠️ THE GUARD THAT MAKES LIFECYCLE OWNERSHIP SAFE.
 *
 * `SmartQrCode` has no `workspace_id` and therefore no global scope. Every other
 * customer-owned model in this codebase is protected by the scope failing
 * closed; this one is protected by nothing except discipline — and discipline is
 * exactly what CLAUDE.md says is not a rule.
 *
 * So the rule is enforced by the build: a raw `SmartQrCode::` query anywhere
 * outside `SmartQrAccess` and the admin namespace is a cross-tenant read waiting
 * to happen, and it fails here.
 *
 * This shipped in the SAME COMMIT as the model. A guard that lags behind the
 * thing it guards protects against the next mistake, not this one.
 */
class SmartQrAccessGuardTest extends TestCase
{
    /**
     * Files permitted to query SmartQrCode directly.
     *
     * `SmartQrAccess` applies the boundary; the admin namespace is *supposed* to
     * see unassigned inventory, which is the entire reason the code is
     * platform-owned. Anything else must go through the service.
     *
     * @var list<string>
     */
    private const ALLOWED = [
        'app/Modules/SmartQr/Services/SmartQrAccess.php',
        'app/Modules/SmartQr/Models/',
        // ⚠️ Generation, and ONLY generation. A batch's codes are platform
        // inventory at birth — there is no workspace to bound them to, which is
        // precisely why SmartQrCode has no scope. Bounding this insert to a
        // tenant would be incoherent, not safer.
        //
        // Narrow deliberately: the Actions directory is not blanket-allowed, so
        // a future action that READS codes for a customer still fails this guard.
        'app/Modules/SmartQr/Actions/GenerateQrBatchAction.php',
        // ⚠️ Slice 3, and admitted for the SAME narrow reason as generation:
        // assignment is a platform act. It resolves a code by id to hand it to
        // a tenant, at a moment when the code belongs to nobody — there is no
        // workspace to bound the lookup to, and bounding it to the RECEIVING
        // workspace would be circular.
        //
        // Still named file-by-file, not by directory: a future action that READS
        // codes on a customer's behalf must still fail this guard.
        'app/Modules/SmartQr/Actions/AssignQrCodesAction.php',
        // ⚠️ Slice 3c. Decides whether a code may be DELETED, which is a
        // platform-lifecycle question about inventory — "was this ever printed,
        // was it ever held by anyone" — not a tenant read. Bounding it to a
        // workspace would answer a different question and, worse, report every
        // assigned code deletable.
        'app/Modules/SmartQr/Services/SmartQrDeletability.php',
        'app/Http/Controllers/Admin/',
        'app/Modules/SmartQr/Http/Controllers/Admin/',
    ];

    /**
     * ⚠️ ONE grep, shared by the assertion AND its control.
     *
     * The first version gave each test its own `shell_exec`. Blinding the main
     * assertion then left it GREEN while the control passed from its own copy —
     * so the control protected nothing. Measured, not reasoned: the stash-check
     * that blinded the grep did not fail.
     *
     * Sharing it means a broken grep fails the control too, which is the only
     * way a control can guard the assertion beside it.
     *
     * @return list<string>
     */
    private function rawQueryLines(): array
    {
        $hits = shell_exec(
            'grep -rn "SmartQrCode::" '.escapeshellarg(base_path('app')).' --include=*.php || true'
        );

        return array_values(array_filter(explode("\n", (string) $hits)));
    }

    #[Test]
    public function no_code_queries_smart_qr_codes_outside_the_access_service(): void
    {
        $lines = $this->rawQueryLines();

        $this->assertNotEmpty($lines,
            'The grep found no SmartQrCode:: query anywhere — not even the permitted ones. It '
            .'is broken, so the assertion below would pass against any violation.');

        $offending = [];

        foreach ($lines as $line) {
            $path = str_replace(base_path().'/', '', explode(':', $line)[0]);

            foreach (self::ALLOWED as $allowed) {
                if (str_starts_with($path, $allowed)) {
                    continue 2;
                }
            }

            $offending[] = $line;
        }

        $this->assertSame([], $offending, implode("\n", [
            '',
            'A raw SmartQrCode:: query appeared outside SmartQrAccess and the admin namespace:',
            '',
            ...$offending,
            '',
            'SmartQrCode is lifecycle-owned: platform-owned at birth, tenant-owned on',
            'assignment. It carries NO workspace_id and therefore NO global scope, so this',
            "query has nothing stopping it returning another tenant's codes — silently.",
            '',
            'Use SmartQrAccess, which joins through smart_qr_assignments. If the query',
            'genuinely needs unassigned inventory it belongs in the admin namespace, and',
            'that must be a deliberate decision rather than a convenient location.',
        ]));
    }

    /** POSITIVE CONTROL, using the SAME grep the assertion uses. */
    #[Test]
    public function the_guard_finds_the_permitted_queries(): void
    {
        $lines = $this->rawQueryLines();

        $this->assertNotEmpty($lines,
            'The guard found no SmartQrCode:: query anywhere — including the ones that are '
            .'supposed to exist. Its grep is broken, so it would pass against any violation.');

        $this->assertTrue(
            (bool) array_filter($lines, fn ($l) => str_contains($l, 'SmartQrAccess.php')),
            'SmartQrAccess itself no longer queries SmartQrCode. Either the service was gutted '
            .'or the grep no longer matches the shape it looks for.'
        );
    }
}
