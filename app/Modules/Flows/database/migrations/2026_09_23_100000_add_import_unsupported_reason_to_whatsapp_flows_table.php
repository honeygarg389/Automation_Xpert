<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_flows', function (Blueprint $table): void {
            // Set when pulling a Flow from Meta hit content decompile() cannot
            // represent WITHOUT LOSS (UnsupportedMetaFlowShapeException). The
            // row then holds placeholder or stale screens, so compiling them
            // and uploading would overwrite the real content on Meta — every
            // action that uploads (sync, publish, and the copy a Duplicate
            // makes) refuses while this is non-null.
            //
            // A dedicated column rather than "meta_sync_error is set": that
            // field is also written by genuine network/auth failures and cleared
            // by unrelated actions, so a later transient error would silently
            // lift the guard. Holds the reason text itself; null means "not
            // known to be lossy". Cleared only by a pull that decompiles cleanly.
            $table->text('import_unsupported_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_flows', function (Blueprint $table): void {
            $table->dropColumn('import_unsupported_reason');
        });
    }
};
