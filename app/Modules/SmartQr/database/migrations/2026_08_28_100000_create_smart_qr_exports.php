<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch-scoped export tracking — one row per ZIP PART.
 *
 * ─── ⚠️ WHY A TABLE AND NOT THE EXISTING FILENAME CONVENTION ────────────────
 *
 * The Inventory bulk export identifies its archives purely by filename
 * (`{Ymd-His}-{random}.zip`) and discovers them by listing the directory. That
 * works for one archive from one ad-hoc selection, and it is deliberately left
 * exactly as it is — this table is a SEPARATE path, not a migration of it.
 *
 * It does not work for a batch split into parts, for two reasons that a
 * filename cannot express:
 *
 *   1. **A missing file is ambiguous.** With filenames only, "part 7 is not on
 *      disk" means either "still queued", "currently rendering" or "failed and
 *      will never arrive" — three states an operator must act on differently,
 *      collapsed into one absence. `status` distinguishes them; `error` says
 *      why the third happened.
 *   2. **The directory listing is capped at 20.** `QrInventoryController`'s
 *      readyExports() takes the 20 most recent files and its download route
 *      re-validates against that same list — so a 10,000-code batch (20 parts,
 *      the `quantity` ceiling) would fill the entire window with its own parts
 *      and push every other admin's archive out of reach. A query keyed on
 *      `batch_id` has no such ceiling.
 *
 * ─── ⚠️ ONE ROW PER PART, WRITTEN BEFORE DISPATCH ───────────────────────────
 *
 * `total_parts` is known up front because the controller counts the batch's
 * codes before dispatching anything, so every row can state "part 3 of 20" from
 * the moment it exists. Rows are created queued and BEFORE the jobs are queued,
 * so a part can never be running with no row to record it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('smart_qr_exports', function (Blueprint $table) {
            $table->id();

            // ⚠️ cascadeOnDelete, unlike smart_qr_codes' restrictOnDelete on the
            // same parent. A code is a physical artefact whose row must not be
            // destroyed silently (that is R-4's whole argument); an export part
            // is a derived, regenerable record of a ZIP. Once the batch is gone
            // the part describes nothing, and keeping it would leave rows whose
            // batch_id points at nothing.
            $table->foreignId('batch_id')->constrained('smart_qr_batches')->cascadeOnDelete();

            $table->unsignedInteger('part_number');
            $table->unsignedInteger('total_parts');

            // Matches GenerateQrExportJob::FORMATS. A plain string, following
            // qr_type's precedent on smart_qr_batches: the format list is
            // application knowledge and can change without a migration.
            $table->string('format', 16);

            // queued -> processing -> ready | failed. String rather than a DB
            // enum, matching smart_qr_batches.status and smart_qr_codes.status.
            $table->string('status', 32)->default('queued');

            // Set only once the archive is on disk. Nullable because it does not
            // exist for queued, processing or failed rows.
            $table->string('path')->nullable();

            // ⚠️ The failure reason, mirroring smart_qr_batches.failure_reason —
            // slice 2's lesson that a stuck job with no explanation is the worst
            // outcome. text, not string: an exception message has no length
            // guarantee.
            $table->text('error')->nullable();

            $table->timestamps();

            // Every read of this table filters by batch — the status panel asks
            // "the parts for THIS batch", never "all parts".
            $table->index('batch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smart_qr_exports');
    }
};
