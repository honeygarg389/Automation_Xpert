<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Restaurant foundation — records that a workspace accepted a specific,
 * immutable legal_document_versions row. `document_version` is a denormalized
 * snapshot string (kept even if the version row were ever deleted); the FK is
 * the source of truth for LegalAcceptance::currentFor()'s comparison.
 *
 * `content_sha256` is a second denormalized snapshot, copied from the
 * accepted LegalDocumentVersion's own content_sha256 at the moment of
 * acceptance (see LegalAcceptance::recordFor()). Belt-and-suspenders
 * alongside that row's immutability guard: even if the guard were ever
 * bypassed or a future migration altered legal_document_versions directly,
 * this acceptance row independently proves exactly which content hash was
 * accepted, without depending on the referenced version row remaining
 * unaltered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_acceptances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('document_type', 32);
            $table->foreignId('legal_document_version_id')->constrained('legal_document_versions')->cascadeOnDelete();
            $table->string('document_version', 32);
            $table->char('content_sha256', 64);
            $table->foreignId('accepted_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('accepted_at');
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->json('evidence')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'document_type', 'accepted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_acceptances');
    }
};
