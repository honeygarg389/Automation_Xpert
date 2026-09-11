<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ═══ A GENERIC ENVELOPE FOR "AN UPLOAD IS IN PROGRESS AND MUST SURVIVE A CRASH" ═
 *
 * `provider_container_id` (scalar) is confirmed insufficient for anything with
 * MORE THAN ONE in-flight identifier: an Instagram carousel needs several child
 * container ids plus one parent id, and a crash between child 3 and child 4 must
 * resume from child 4, not recreate children 1-3 and orphan them on Meta's side
 * for 24 hours — the exact fault the original single-container polling fix
 * closed, now one level deeper.
 *
 * ─── THE SHAPE, AND WHY IT GENERALIZES ACROSS FOUR DIFFERENT UPLOAD MODELS ───
 *
 *   {
 *     "phase":     string,        // where in its own state machine this is
 *     "items":     [ {...}, ... ],// an ORDERED list of sub-steps already done
 *     "parent_id": string|null,   // the one id this whole upload produces
 *     "meta":      { ... }        // whatever else THIS provider's flow needs
 *   }
 *
 * Every async upload this app will ever drive reduces to the same question:
 * "which steps are already done, and what do I still need to finish?" — only
 * the CONTENTS of `items` and `meta` differ per provider:
 *
 *   Instagram carousel  items = children [{index, media_url, container_id}]
 *                        parent_id = the CAROUSEL parent container, once made
 *   Facebook resumable   items unused; meta = {session_id, file_offset, handle}
 *   LinkedIn video       items = uploaded parts [{index, etag}]
 *                        parent_id = the video URN; meta = {upload_token}
 *   X chunked upload     items = appended segments [{segment_index, status}]
 *                        parent_id = the media_id; meta = {total_segments}
 *
 * So Facebook/LinkedIn/X's future branches reuse this SAME column rather than
 * each adding a bespoke one — `items` for whatever repeats, `parent_id` for the
 * single terminal identifier, `meta` for the rest. A provider that needs a field
 * no other provider has just adds it to ITS OWN `meta`, not to the schema.
 *
 * ─── provider_container_id IS KEPT, NOT MIGRATED ─────────────────────────────
 *
 * The single-container path (one image, one Reel) is the WORKING, ALREADY-
 * TESTED case. Moving it into provider_upload_state for consistency would touch
 * every line of the existing reuse/expiry/forget logic for zero behavioural
 * gain — pure risk on a path nothing here is fixing. provider_upload_state is
 * additive: read and written ONLY when a single scalar id cannot represent the
 * upload (carousel today; resumable uploads and chunked uploads in later
 * branches). A row uses at most one of the two, never both.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_media_post_accounts', function (Blueprint $table) {
            $table->json('provider_upload_state')->nullable()->after('container_created_at');
        });
    }

    public function down(): void
    {
        Schema::table('social_media_post_accounts', function (Blueprint $table) {
            $table->dropColumn('provider_upload_state');
        });
    }
};
