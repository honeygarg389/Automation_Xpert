<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The admin lock on a tenant's active/inactive toggle. §11.
 *
 * ═══ ⚠️ WHY A FLAG AND NOT A NEW STATUS VALUE ═══════════════════════════════
 *
 * `status` answers "is this QR serving?"; the lock answers "may the tenant
 * change that?". They are independent, and folding them into one column loses
 * the common case — locked AND currently active — outright.
 *
 * It would also break the public redirect silently. SmartQrRedirectResolver
 * routes on `status !== ASSIGNMENT_ACTIVE`, so a 'locked' status value would
 * take every locked code offline the moment it was set, with no code changed
 * and nothing to point at. R-10's two-vocabularies rule, one level down.
 *
 * ⚠️ REASON + ACTOR + TIMESTAMP, because CLAUDE.md §9 requires all three for a
 * manual override, and because "who locked this and why" is the first question
 * asked when a customer calls about it. Mirrors assigned_by_admin_id on this
 * same table and failure_reason/failed_at on smart_qr_batches.
 *
 * ⚠️ THESE COLUMNS ARE DELIBERATELY ABSENT FROM SmartQrAssignment::$fillable.
 * The customer update path calls $assignment->update($validated) with tenant
 * input, so a fillable lock column would be a tenant self-unlock. See the model,
 * and the mass-assignment test that proves the exclusion rather than trusting it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('smart_qr_assignments', function (Blueprint $table) {
            $table->boolean('admin_locked')->default(false)->after('status');
            $table->text('lock_reason')->nullable()->after('admin_locked');

            // nullOnDelete, matching assigned_by_admin_id: removing an admin
            // account must not delete the lock or the assignment with it. The
            // lock survives its author; the audit log keeps the name.
            $table->foreignId('locked_by_admin_id')->nullable()->after('lock_reason')
                ->constrained('admin_users')->nullOnDelete();
            $table->timestamp('locked_at')->nullable()->after('locked_by_admin_id');

            // Locked assignments are looked up as a set ("what has the platform
            // frozen?"), which is a small fraction of the table.
            $table->index('admin_locked');
        });
    }

    public function down(): void
    {
        Schema::table('smart_qr_assignments', function (Blueprint $table) {
            $table->dropIndex(['admin_locked']);
            $table->dropConstrainedForeignId('locked_by_admin_id');
            $table->dropColumn(['admin_locked', 'lock_reason', 'locked_at']);
        });
    }
};
