<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remember the media container a publish attempt created.
 *
 * ⚠️ WHY THIS IS NOT `platform_post_id`. That column holds the id of the
 * PUBLISHED post and is written on success. A container id is a different thing
 * with a different lifetime: it identifies an unpublished, still-processing
 * upload that expires after 24 hours and may never become a post at all.
 * Overloading one column with both would make "has this been published?"
 * unanswerable.
 *
 * Instagram's publish is two calls — create a container, then publish it — and
 * the container id was previously a local variable, discarded when the driver
 * returned. Every retry therefore created a BRAND NEW container and immediately
 * tried to publish it, which is the shape that made post 15 take three attempts
 * and 474 seconds: each attempt raced the same processing delay from scratch and
 * left an orphaned container behind on Meta's side.
 *
 * Nullable and unused by every other network. Only the Instagram driver reads or
 * writes these; a single-step publish has no container to remember.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_media_post_accounts', function (Blueprint $table) {
            $table->string('provider_container_id', 255)->nullable()->after('platform_post_id');
            // Drives the 24-hour expiry check, so a stale container is replaced
            // rather than polled forever.
            $table->timestamp('container_created_at')->nullable()->after('provider_container_id');
        });
    }

    public function down(): void
    {
        Schema::table('social_media_post_accounts', function (Blueprint $table) {
            $table->dropColumn(['provider_container_id', 'container_created_at']);
        });
    }
};
