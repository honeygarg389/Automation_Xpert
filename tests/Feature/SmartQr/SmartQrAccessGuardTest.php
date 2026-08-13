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
        'app/Http/Controllers/Admin/',
        'app/Modules/SmartQr/Http/Controllers/Admin/',
    ];

    #[Test]
    public function no_code_queries_smart_qr_codes_outside_the_access_service(): void
    {
        $hits = shell_exec(
            'grep -rn "SmartQrCode::" '.escapeshellarg(base_path('app')).' --include=*.php || true'
        );

        $offending = [];

        foreach (array_filter(explode("\n", (string) $hits)) as $line) {
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

    /** POSITIVE CONTROL: the guard can actually find something. */
    #[Test]
    public function the_guard_finds_the_permitted_queries(): void
    {
        $hits = shell_exec(
            'grep -rln "SmartQrCode::" '.escapeshellarg(base_path('app')).' --include=*.php || true'
        );

        $this->assertNotEmpty(trim((string) $hits),
            'The guard found no SmartQrCode:: query anywhere — including the ones that are '
            .'supposed to exist. Its grep is broken, so it would pass against any violation.');
    }
}
