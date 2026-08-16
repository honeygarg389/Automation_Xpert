<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Smart QR — slice 1. Schema and the ownership model, wired to nothing.
 *
 * ─── ⚠️ LIFECYCLE OWNERSHIP: THE SHAPE THIS MODULE IS BUILT AROUND ──────────
 *
 * A QR code is PLATFORM-OWNED AT BIRTH and TENANT-OWNED AFTER ASSIGNMENT. It is
 * printed before anyone knows which customer will receive it, and it can be
 * reassigned later.
 *
 * That breaks the pattern the rest of this codebase uses. `BelongsToWorkspace`
 * fails closed: an unassigned code carries no workspace, so `where workspace_id
 * = :id` matches for NO value of :id — the code would be invisible to every
 * tenant AND to the Super Admin inventory screen that exists to manage exactly
 * those rows.
 *
 * So `smart_qr_codes` carries NO `workspace_id` column at all. Tenancy lives on
 * `smart_qr_assignments`, which is where it actually belongs: the assignment IS
 * the tenancy, and it has a beginning and an end.
 *
 * ─── ⚠️ THE SPEC IS AMBIGUOUS HERE, AND THIS IS THE RESOLUTION ──────────────
 *
 * The spec assigns a QR to a "tenant/customer" and separately requires that the
 * WhatsApp channel and the assigned user "belong to the selected tenant". This
 * codebase has BOTH a `client` (organisation) and a `workspace`, and
 * `ChannelAccount` is WORKSPACE-scoped — so for a client with three workspaces,
 * "the channel belongs to the tenant" has no single answer.
 *
 * RULED: assignment is to a WORKSPACE. CLAUDE.md rule 2 makes `workspace_id` the
 * operational tenant boundary for all customer-owned data, and the spec was
 * written without knowing both existed. Recorded here so it is not re-litigated.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── The print run ───────────────────────────────────────────────────
        Schema::create('smart_qr_batches', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('batch_number', 64)->unique();
            $table->string('batch_name');
            $table->string('prefix', 16);
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('serial_start')->default(1);

            // ⚠️ OWNER RULING, NOT A SPEC REQUIREMENT. The spec carries a
            // `qr_type` field but never enumerates its values; the placement
            // vocabulary (Counter, Table, Reception, …) was decided after the
            // spec was written. Kept as a plain string so the list can change
            // without a migration.
            $table->string('qr_type', 32)->nullable();

            $table->string('status', 32)->default('draft');
            $table->unsignedInteger('generated_count')->default(0);
            $table->unsignedInteger('printed_count')->default(0);
            $table->text('default_message')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by_admin_id')->nullable()
                ->constrained('admin_users')->nullOnDelete();
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('printed_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        // ── The physical artefact. NO workspace_id, deliberately. ───────────
        Schema::create('smart_qr_codes', function (Blueprint $table) {
            $table->id();

            // ⚠️ TWO DIFFERENT IDENTIFIERS, AND THEY MUST NOT BE CONFLATED.
            //
            // serial_number is PRINTED and human-readable (AX-000001). It is on
            // the artwork, so it is public by definition and sequential by
            // design — an operator reads it off a sticker.
            //
            // public_token is what the QR ENCODES. It must be
            // cryptographically random and non-sequential, because it addresses
            // a tenant from an unauthenticated request: guessing one would let a
            // stranger enumerate every customer's QR destination.
            $table->string('serial_number', 64)->unique();
            $table->string('public_token', 64)->unique();

            $table->foreignId('batch_id')->constrained('smart_qr_batches')->restrictOnDelete();
            $table->string('status', 32)->default('generated');
            $table->timestamp('printed_at')->nullable();
            $table->timestamps();

            $table->index(['batch_id', 'status']);
        });

        // ── Where tenancy lives, and where it ends ──────────────────────────
        Schema::create('smart_qr_assignments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('smart_qr_code_id')->constrained('smart_qr_codes')->cascadeOnDelete();

            // The tenant boundary. NOT NULL: an assignment without a workspace
            // is not an assignment.
            $table->unsignedBigInteger('workspace_id');

            $table->unsignedBigInteger('channel_account_id')->nullable();
            $table->unsignedBigInteger('assigned_user_id')->nullable();

            $table->string('name')->nullable();
            $table->string('qr_type', 32)->nullable();
            $table->text('default_message')->nullable();

            $table->string('status', 32)->default('active');
            $table->timestamp('assigned_at');

            // ⚠️ THE PERIOD. A NULL unassigned_at means this assignment is
            // current. History is preserved rather than deleted, because the
            // spec requires a reassigned QR to hide the PREVIOUS tenant's
            // analytics — which is only expressible if the old period survives.
            $table->timestamp('unassigned_at')->nullable();

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('assigned_by_admin_id')->nullable()
                ->constrained('admin_users')->nullOnDelete();
            $table->json('config_snapshot')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'status']);
            $table->index(['smart_qr_code_id', 'unassigned_at']);
        });

        // ⚠️ ONE CURRENT ASSIGNMENT PER CODE — enforced by the DATABASE.
        //
        // Two current assignments would mean two tenants both believing they own
        // the code, and a scan redirecting to whichever row was read first. That
        // is the BUG-019 shape: a routing identifier claimed by two workspaces,
        // resolved arbitrarily, for months.
        //
        // MySQL has no partial index, so a generated column carries the
        // condition: it holds the code id only while the assignment is current,
        // and NULL otherwise. NULLs do not collide in a unique index, so any
        // number of ENDED assignments coexist while only one may be open.
        //
        // Application-level checks were rejected: the constraint has to hold
        // against a seeder, a raw insert, and two concurrent admin requests —
        // none of which run the model's hooks.
        // Two statements, not one: MySQL refuses to add a generated column and an
        // index over it in a single ALTER when the source column carries a
        // foreign key (error 1215), and the column must be VIRTUAL rather than STORED for
        // the same reason — a stored column derived from an FK column forces a
        // rebuild MySQL refuses. A secondary index over a virtual column is
        // supported and enforces uniqueness identically.
        DB::statement('
            ALTER TABLE smart_qr_assignments
            ADD COLUMN current_code_id BIGINT UNSIGNED
                GENERATED ALWAYS AS (IF(unassigned_at IS NULL, smart_qr_code_id, NULL)) VIRTUAL
        ');

        DB::statement('
            ALTER TABLE smart_qr_assignments
            ADD UNIQUE INDEX smart_qr_assignments_one_current_per_code (current_code_id)
        ');

        // ── Scans, keyed by ASSIGNMENT rather than workspace ────────────────
        //
        // ⚠️ Slice 1 creates this table with only the columns the canary needs.
        // Slice 4 adds the rest (ip_hash, ua_hash, bot flags, device, referer).
        // It exists now because the canary must prove that a reassignment hides
        // the prior tenant's scans, and that claim is untestable without scans.
        //
        // ⚠️ NO workspace_id, and that is the point. Denormalising a tenant onto
        // a row whose tenant CHANGES creates two sources of truth that disagree
        // the moment a QR is reassigned — the old scans would keep claiming the
        // old workspace while the code belongs to a new one. Keying by
        // assignment_id makes the period intrinsic: old scans point at the old
        // assignment and are simply not reachable through the new one.
        Schema::create('smart_qr_scan_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('smart_qr_assignment_id')
                ->constrained('smart_qr_assignments')->cascadeOnDelete();
            $table->timestamp('scanned_at');
            $table->timestamps();

            $table->index(['smart_qr_assignment_id', 'scanned_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smart_qr_scan_events');
        Schema::dropIfExists('smart_qr_assignments');
        Schema::dropIfExists('smart_qr_codes');
        Schema::dropIfExists('smart_qr_batches');
    }
};
