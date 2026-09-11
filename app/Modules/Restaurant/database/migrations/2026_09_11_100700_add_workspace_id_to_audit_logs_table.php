<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds an optional workspace_id reference to audit_logs so restaurant/POS
 * events can be attributed to a workspace. No AuditLogService signature
 * change — callers that want it can set it via array merge into $meta or a
 * follow-up write; this migration only adds the column.
 *
 * Appended rather than positioned with ->after(): naming a position forces a
 * full table rebuild, where appending allows ALGORITHM=INSTANT (same
 * reasoning as the Smart QR failure_reason migration).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('workspace_id')->nullable();
            $table->foreign('workspace_id')->references('id')->on('workspaces')->nullOnDelete();
            $table->index('workspace_id');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropForeign(['workspace_id']);
            $table->dropIndex(['workspace_id']);
            $table->dropColumn('workspace_id');
        });
    }
};
