<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slice 4 — the columns §10 needs, and the ones it asks for that are absent.
 *
 * Slice 1 created this table with only what the ownership canary needed. This
 * adds the scan detail the public redirect records.
 *
 * ─── ⚠️ NO RAW IP, EVER ─────────────────────────────────────────────────────
 *
 * §10: "Do not permanently store raw IP addresses." Both `ip_hash` and `ua_hash`
 * are keyed HMACs, and the key is FIXED — see SmartQrScanFingerprint. Rotating
 * it silently invalidates every unique-scan comparison ever made, because the
 * same visitor hashes to a different value afterwards and every repeat scan
 * counts as new.
 *
 * ─── ⚠️ WHAT IS DELIBERATELY ABSENT ─────────────────────────────────────────
 *
 * `qr_code_id` — §10's aggregate dimension list names it, but it is reachable
 *   through the assignment. Denormalising an id onto what will be the largest
 *   table in the system is R-12's argument again: a second source of truth that
 *   must be kept in step forever.
 *
 * `workspace_id` — R-4 forbids it outright. A scan belongs to an assignment
 *   PERIOD, and the period is what makes "a reassignment hides the previous
 *   tenant's analytics" true without date arithmetic.
 *
 * `device_category` — §10 asks for it "where reliable". Reliable UA parsing
 *   needs a library this project does not have, and a hand-rolled one is wrong
 *   in ways nobody notices. `ua_hash` alone satisfies the unique-scan rule.
 *   Deferred until something consumes it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('smart_qr_scan_events', function (Blueprint $table) {
            // 64 hex chars = sha256. Nullable because a request can genuinely
            // arrive with no resolvable IP (CLI, some proxies) and a scan with
            // an unknown fingerprint is still a scan.
            $table->string('ip_hash', 64)->nullable();
            $table->string('ua_hash', 64)->nullable();

            // ⚠️ A FLAG, NOT A FILTER. §10 says not to treat preview crawlers as
            // real scans — but a WhatsApp crawler fetching the link is evidence
            // the link was SHARED, which is signal. And any bot that lies about
            // its user agent gets counted regardless, so dropping the row buys
            // less than it costs. Excluded from aggregates, never discarded:
            // recording is reversible, deleting is not.
            $table->boolean('is_bot')->default(false);

            // ⚠️ HOST ONLY. A full referer carries query strings, and query
            // strings carry tokens and PII. The host answers "where was this
            // shared" without storing anything we would have to defend.
            $table->string('referer_host', 255)->nullable();

            // Computed ONCE at write, in the job. Deciding it at read time means
            // a self-join over the biggest table in the system on every
            // dashboard load, which is exactly what §10's aggregates exist to
            // avoid.
            $table->boolean('is_unique')->default(true);

            // The unique-scan lookup: "has this fingerprint hit this assignment
            // within the window". Ordered assignment -> fingerprint -> time
            // because that is the order the predicate narrows.
            $table->index(
                ['smart_qr_assignment_id', 'ip_hash', 'ua_hash', 'scanned_at'],
                'smart_qr_scan_events_fingerprint_idx'
            );

            // Bot exclusion is applied to nearly every aggregate query.
            $table->index(['is_bot', 'scanned_at']);
        });
    }

    public function down(): void
    {
        Schema::table('smart_qr_scan_events', function (Blueprint $table) {
            $table->dropIndex('smart_qr_scan_events_fingerprint_idx');
            $table->dropIndex(['is_bot', 'scanned_at']);
            $table->dropColumn(['ip_hash', 'ua_hash', 'is_bot', 'referer_host', 'is_unique']);
        });
    }
};
