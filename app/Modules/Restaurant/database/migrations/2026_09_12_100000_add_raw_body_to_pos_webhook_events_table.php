<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1B — exact raw HTTP body storage, alongside the existing decoded
 * `raw_payload` JSON column (kept, not replaced).
 *
 * Two separate columns because they serve two different purposes that
 * cannot share a representation:
 *
 *   raw_body     the EXACT bytes of $request->getContent(), before any JSON
 *                decoding. This is what payload_hash is computed from (see
 *                PosWebhookEvent's CRITICAL docblock warning), and it is
 *                also the only thing that can preserve a MALFORMED delivery
 *                byte-for-byte — a JSON column physically cannot hold
 *                invalid JSON at all, so a payload that fails to decode
 *                would otherwise be unrecoverable for debugging/replay.
 *   raw_payload  the decoded PHP array of a VALID delivery, stored as JSON
 *                for queryability. Re-serializing this to compute a hash
 *                would defeat the whole point — whitespace/key-order can
 *                change silently — which is exactly why raw_body exists.
 *
 * ⚠️ REVISED: `MEDIUMBLOB`, not `mediumText`. The first version of this
 * migration used `$table->mediumText('raw_body')`, reasoned from "no
 * ->binary() precedent exists in this codebase." That reasoning was wrong to
 * act on: the requirement is EXACT BYTES, and MEDIUMTEXT is CHARACTER
 * storage — MySQL associates a charset/collation with it (utf8mb4 here),
 * which means the stored value is subject to charset validation and
 * collation-driven comparison/sorting semantics that plain binary storage
 * does not have. A column type that can, even in principle, involve a
 * charset is the wrong tool for "this must never be alterable by encoding,"
 * regardless of whether any codebase precedent already used it elsewhere —
 * an absent precedent is not a reason to accept a weaker guarantee than the
 * requirement calls for.
 *
 * `MEDIUMBLOB` (16MB, same ceiling as MEDIUMTEXT, but pure bytes with no
 * charset/collation at all) is the correct MySQL type. Laravel's Schema
 * Blueprint has no builtin primitive for it — grepped
 * vendor/laravel/framework's Blueprint class directly: the only blob-family
 * method is `binary($column, $length = null)`, whose grammar
 * (MySqlGrammar::typeBinary()) maps a bare call to plain `BLOB` (64KB) or,
 * with a length, to `BINARY`/`VARBINARY` — neither reaches MEDIUMBLOB's 16MB
 * ceiling. So this migration adds the column via a raw DB::statement()
 * rather than the Blueprint DSL — the "precise DB-level binary migration
 * approach" for a type Laravel's schema builder cannot express. down()
 * still uses the ordinary Blueprint dropColumn(), which works regardless of
 * how the column was created.
 *
 * See PetpoojaWebhookControllerRawBodyByteFidelityTest for a byte-for-byte
 * round-trip proof (including bytes that are NOT valid UTF-8, which a text
 * column could not have stored correctly at all).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE pos_webhook_events ADD COLUMN raw_body MEDIUMBLOB NULL');
    }

    public function down(): void
    {
        Schema::table('pos_webhook_events', function (Blueprint $table) {
            $table->dropColumn('raw_body');
        });
    }
};
