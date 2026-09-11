<?php

namespace App\Modules\Restaurant\Models;

use App\Models\Concerns\BelongsToWorkspace;
use App\Modules\Restaurant\Exceptions\ImmutableLegalAcceptanceException;
use App\Modules\Restaurant\Exceptions\NoPublishedLegalDocumentException;
use Database\Factories\LegalAcceptanceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $workspace_id
 * @property string $document_type
 * @property int $legal_document_version_id
 * @property string $document_version
 * @property string $content_sha256
 * @property int $accepted_by_user_id
 * @property Carbon $accepted_at
 * @property string|null $ip
 * @property string|null $user_agent
 * @property array<string, mixed>|null $evidence
 */
class LegalAcceptance extends Model
{
    use BelongsToWorkspace;

    /** @use HasFactory<LegalAcceptanceFactory> */
    use HasFactory;

    /**
     * Every non-timestamp column this table has. There is no status/notes
     * field or anything else left unprotected — the full column list (see
     * the create_legal_acceptances_table migration) is exactly this set
     * plus id/created_at/updated_at, so this guard covers the entire row.
     */
    private const IMMUTABLE_AFTER_CREATE = [
        'workspace_id',
        'document_type',
        'legal_document_version_id',
        'document_version',
        'content_sha256',
        'accepted_by_user_id',
        'accepted_at',
        'ip',
        'user_agent',
        'evidence',
    ];

    protected $fillable = self::IMMUTABLE_AFTER_CREATE;

    protected static function booted(): void
    {
        // updating() fires ONLY on an UPDATE of an already-persisted row —
        // never on the initial INSERT (that's creating()/created()). This is
        // deliberate: recordFor()'s create() must go through untouched, and
        // the requirement is specifically "append-only AFTER creation", not
        // "never writable at all".
        static::updating(function (self $acceptance) {
            foreach (self::IMMUTABLE_AFTER_CREATE as $field) {
                if ($acceptance->isDirty($field)) {
                    throw new ImmutableLegalAcceptanceException(
                        "Cannot modify '{$field}' on legal_acceptances#{$acceptance->id}: ".
                        'an acceptance record is append-only after creation.'
                    );
                }
            }
        });
    }

    protected function casts(): array
    {
        return [
            'accepted_at' => 'datetime',
            'evidence' => 'array',
        ];
    }

    protected static function newFactory(): LegalAcceptanceFactory
    {
        return LegalAcceptanceFactory::new();
    }

    /**
     * Whether the workspace's acceptance of $documentType is CURRENT — i.e. it
     * points at the version that is published right now, not merely at some
     * version that was once accepted. Publishing a new version supersedes
     * every prior acceptance without needing to touch this table: a workspace
     * that accepted v1 has "unsatisfied" consent again the moment v2 goes
     * live, because currentPublished()'s id no longer matches what was
     * accepted.
     *
     * `withoutWorkspaceScope()`: this takes an explicit $workspaceId parameter
     * and its own where() IS the boundary — the same fail-open shape as
     * UsageMeter::current() and ContactCapacity::remaining(). Under the
     * scope, a caller with no ambient workspace context (a queue job, a
     * console command, this very method called from outside a request)
     * would have `1 = 0` ANDed on, and this would report EVERY workspace's
     * acceptance as missing regardless of whether it actually accepted —
     * the opposite of fail-open would be silently telling every workspace
     * it has not accepted terms it has. Measured: this returned null for a
     * real acceptance until the bypass was added.
     */
    public static function currentFor(int $workspaceId, string $documentType): ?self
    {
        $published = LegalDocumentVersion::currentPublished($documentType);

        if (! $published) {
            return null;
        }

        return static::withoutWorkspaceScope('reason: takes an explicit workspace_id parameter and its own where() IS the boundary; fails open like UsageMeter::current().')
            ->where('workspace_id', $workspaceId)
            ->where('document_type', $documentType)
            ->where('legal_document_version_id', $published->id)
            ->orderByDesc('accepted_at')
            ->first();
    }

    /**
     * The guarded creation path — the creation-time counterpart to
     * currentFor()'s read-time check. Resolves the CURRENTLY published
     * version itself, rather than trusting a caller to pass one in, so a
     * future caller cannot accidentally create an acceptance against a
     * draft/retired version by constructing the row directly.
     *
     * The `status`/`published_slot` re-check below is defensive, not
     * decorative: currentPublished() already filters on published_slot=1,
     * so this can only trip if that guarantee were ever violated elsewhere
     * (the CHECK constraint says it can't, but this is the same
     * belt-and-suspenders posture as content_sha256's independent copy —
     * this method does not take the DB constraint's word for it alone).
     *
     * No controller calls this yet (no onboarding UI exists) — built now so
     * the guard exists before the first caller does, not added reactively
     * after the first accidental draft-acceptance.
     *
     * @param  array<string, mixed>  $evidence
     */
    public static function recordFor(
        int $workspaceId,
        string $documentType,
        int $userId,
        array $evidence = [],
        ?Request $request = null,
    ): self {
        $published = LegalDocumentVersion::currentPublished($documentType);

        if (
            ! $published
            || $published->status !== LegalDocumentVersion::STATUS_PUBLISHED
            || $published->published_slot !== LegalDocumentVersion::PUBLISHED_SLOT
        ) {
            throw new NoPublishedLegalDocumentException(
                "No published legal document version exists for document_type '{$documentType}'."
            );
        }

        return static::create([
            'workspace_id' => $workspaceId,
            'document_type' => $documentType,
            'legal_document_version_id' => $published->id,
            'document_version' => $published->version,
            'content_sha256' => $published->content_sha256,
            'accepted_by_user_id' => $userId,
            'accepted_at' => now(),
            'ip' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'evidence' => $evidence,
        ]);
    }
}
