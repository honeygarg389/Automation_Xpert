<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-batch QR logo. §14's artwork logo becomes a property of the PRINT RUN
 * rather than of the installation.
 *
 * ⚠️ A PAIR, not a single path — `logo_disk` travels with `logo_path` for the
 * same reason `app_logo_disk` travels with `app_logo_path`: a path alone is
 * meaningless once more than one disk exists, and this codebase has five
 * (`local`, `public`, `s3`, `do_spaces`, `wasabi`). Storing only the path makes
 * the reader guess, and a wrong guess resolves to a file that is absent rather
 * than to an error.
 *
 * ⚠️ Both NULLABLE, and null means PLAIN. There is deliberately no default and
 * no fallback: a batch with no uploaded logo renders an unbranded QR. See
 * SmartQrImageRenderer::batchLogoPath() for why that is the only safe absence
 * behaviour.
 *
 * Additive, and appended last rather than positioned with ->after(), so MySQL
 * can take ALGORITHM=INSTANT instead of rebuilding the table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('smart_qr_batches', function (Blueprint $table) {
            $table->string('logo_path')->nullable();
            $table->string('logo_disk')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('smart_qr_batches', function (Blueprint $table) {
            $table->dropColumn(['logo_path', 'logo_disk']);
        });
    }
};
