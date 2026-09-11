<?php

namespace App\Modules\Restaurant\Models;

use App\Modules\Restaurant\Exceptions\ImmutableLegalDocumentException;
use Database\Factories\LegalDocumentVersionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A single immutable snapshot of a legal document's text, at one version.
 *
 * `content_body` is written once and never edited — a new legal text is a new
 * row, not an update to an existing one. See the migration's docblock for why
 * this does not reuse CmsPage (which has no such guarantee).
 *
 * `published_slot` (not `status`) is the authoritative published-marker: it is
 * the column the DB's UNIQUE (document_type, published_slot) constraint and
 * CHECK constraint protect. `status` is a synchronized, human-readable label —
 * see LegalDocumentPublishingService::publish(), the only place both are
 * written together.
 *
 * @property int $id
 * @property string $document_type
 * @property string $version
 * @property string $status
 * @property string $content_body
 * @property string $content_sha256
 * @property int|null $published_slot
 * @property Carbon|null $published_at
 * @property Carbon|null $retired_at
 * @property int|null $created_by_admin_id
 */
class LegalDocumentVersion extends Model
{
    /** @use HasFactory<LegalDocumentVersionFactory> */
    use HasFactory;

    public const TYPE_TERMS = 'terms';

    public const TYPE_DPA = 'dpa';

    public const TYPE_RESTAURANT_DECLARATION = 'restaurant_declaration';

    public const DOCUMENT_TYPES = [
        self::TYPE_TERMS,
        self::TYPE_DPA,
        self::TYPE_RESTAURANT_DECLARATION,
    ];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_RETIRED = 'retired';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PUBLISHED,
        self::STATUS_RETIRED,
    ];

    public const PUBLISHED_SLOT = 1;

    /**
     * Fields the immutability guard in booted() protects once a row is no
     * longer 'draft'. Deliberately excludes status/published_slot/
     * published_at/retired_at — those are exactly what
     * LegalDocumentPublishingService::publish() legitimately changes on a
     * non-draft row.
     */
    private const IMMUTABLE_ONCE_NOT_DRAFT = ['document_type', 'version', 'content_body'];

    protected $fillable = [
        'document_type',
        'version',
        'status',
        'content_body',
        'published_slot',
        'published_at',
        'retired_at',
        'created_by_admin_id',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'retired_at' => 'datetime',
        ];
    }

    protected static function newFactory(): LegalDocumentVersionFactory
    {
        return LegalDocumentVersionFactory::new();
    }

    protected static function booted(): void
    {
        static::saving(function (self $version) {
            // ENFORCEMENT, not just the class docblock's claim: once a row
            // has left 'draft', document_type/version/content_body may never
            // change again. Checked against getOriginal('status') — the
            // status the row ALREADY had in the DB — rather than the
            // in-memory $version->status, so a save that tries to smuggle a
            // content edit through alongside an (illegitimate) status
            // rollback to 'draft' is still caught. Only applies to updates:
            // a brand-new row being created directly as non-draft is not
            // this guard's concern (the publish service never does that; it
            // always creates draft, then transitions an already-persisted
            // row).
            if ($version->exists && $version->getOriginal('status') !== self::STATUS_DRAFT) {
                foreach (self::IMMUTABLE_ONCE_NOT_DRAFT as $field) {
                    if ($version->isDirty($field)) {
                        throw new ImmutableLegalDocumentException(
                            "Cannot modify '{$field}' on legal_document_versions#{$version->id}: ".
                            "its status is '{$version->getOriginal('status')}', not 'draft'."
                        );
                    }
                }
            }

            // content_sha256 is a FULLY DERIVED value — recomputed on EVERY
            // save, unconditionally, not only when content_body isDirty().
            // This closes a separate loophole from the guard above: that
            // guard only blocks a save that touches document_type/version/
            // content_body, so a save that leaves content_body untouched but
            // sets content_sha256 directly to an arbitrary value would sail
            // straight past it and persist a false hash. Recomputing
            // unconditionally means content_sha256 silently self-corrects to
            // the true hash of whatever content_body currently holds on
            // every single save — a direct assignment to it can never
            // outlive this hook, published row or not.
            //
            // This does not create a spurious dirty-state false positive: if
            // content_body is unchanged, this reassigns content_sha256 to
            // the value it already has, and Eloquent does not mark an
            // attribute dirty when set to its current value (see
            // content_sha256_reassignment_is_a_no_op_when_content_body_is_unchanged
            // in LegalDocumentVersionPublishingTest, which asserts this
            // rather than assuming it).
            $version->content_sha256 = hash('sha256', (string) $version->content_body);
        });
    }

    /**
     * The currently live version of a document type, if any.
     *
     * Queries `published_slot = 1`, not `status = 'published'` — the slot
     * column is the one the DB constraints actually protect, so it is the
     * authoritative signal. `status` is kept in sync by
     * LegalDocumentPublishingService::publish() and is a label, not a source
     * of truth.
     */
    public static function currentPublished(string $documentType): ?self
    {
        return static::query()
            ->where('document_type', $documentType)
            ->where('published_slot', self::PUBLISHED_SLOT)
            ->first();
    }
}
