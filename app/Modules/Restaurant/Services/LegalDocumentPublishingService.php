<?php

namespace App\Modules\Restaurant\Services;

use App\Modules\Restaurant\Models\LegalDocumentVersion;
use Illuminate\Support\Facades\DB;

/**
 * Publishes a legal document version, atomically retiring whatever was
 * published before it.
 *
 * ─── WHY BOTH THE TRANSACTION+LOCK AND THE DB CONSTRAINT ARE NEEDED ────────
 *
 * The transaction + `lockForUpdate()` prevent a LOGICAL race between two
 * concurrent calls to this method: without the lock, two callers could both
 * read the same "currently published" row, both decide to retire it, and
 * both decide to publish their own new version — a lost-update on the
 * business logic, not just on a column. The lock serializes them: the second
 * caller blocks until the first commits, then re-reads and acts on the
 * now-current state.
 *
 * Neither the transaction nor the lock protects against a write path that
 * does not go through this method at all — a future direct DB write, a
 * console command, a bug in this method itself that sets the columns wrong.
 * The `UNIQUE (document_type, published_slot)` constraint (see the
 * legal_document_versions migration) is the backstop for exactly that case:
 * it physically cannot be bypassed by forgetting to call this service, only
 * by the database itself misbehaving.
 */
class LegalDocumentPublishingService
{
    public function publish(LegalDocumentVersion $newVersion): LegalDocumentVersion
    {
        return DB::transaction(function () use ($newVersion) {
            $current = LegalDocumentVersion::query()
                ->where('document_type', $newVersion->document_type)
                ->where('published_slot', LegalDocumentVersion::PUBLISHED_SLOT)
                ->lockForUpdate()
                ->first();

            if ($current && $current->is($newVersion)) {
                return $newVersion;
            }

            if ($current) {
                $current->forceFill([
                    'status' => LegalDocumentVersion::STATUS_RETIRED,
                    'published_slot' => null,
                    'retired_at' => now(),
                ])->save();
            }

            $newVersion->forceFill([
                'status' => LegalDocumentVersion::STATUS_PUBLISHED,
                'published_slot' => LegalDocumentVersion::PUBLISHED_SLOT,
                'published_at' => now(),
            ])->save();

            return $newVersion->refresh();
        });
    }
}
