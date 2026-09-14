<?php

namespace App\Modules\Shared\Services;

use App\Events\ContactCreated;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\ContactTag;
use App\Modules\Shared\Models\Segment;
use App\Services\StorageManager;
use App\Support\PhoneNumber;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ContactService
{
    public function __construct(private StorageManager $storageManager) {}

    /**
     * Upsert a contact by phone (E.164) within a workspace.
     * Falls back to email lookup if phone is absent.
     *
     * @param  bool  $dispatchCreatedEvent  MUST be false for bulk imports/syncs — both
     *                                      bulkImport() (CSV) and importGridRows() (XLSX)
     *                                      below pass false, matching the same convention
     *                                      already used by SyncStoreCustomersJob and
     *                                      BackfillStoreOrdersJob. true dispatches
     *                                      ContactCreated, which fires
     *                                      AutomationTriggerListener::handleContactCreated()
     *                                      (any `contact.created` automation — welcome
     *                                      messages, WhatsApp/SMS/email sends) and
     *                                      DispatchOutboundWebhookListener::handleContactCreated()
     *                                      for EVERY row — appropriate for one live contact,
     *                                      never for thousands of historical import rows.
     */
    public function upsert(int $workspaceId, array $data, bool $dispatchCreatedEvent = true): Contact
    {
        $lookup = [];

        if (! empty($data['phone_e164'])) {
            $lookup = ['workspace_id' => $workspaceId, 'phone_e164' => $data['phone_e164']];
        } elseif (! empty($data['email'])) {
            $lookup = ['workspace_id' => $workspaceId, 'email' => $data['email']];
        }

        if (empty($lookup)) {
            $contact = Contact::create(array_merge($data, ['workspace_id' => $workspaceId]));
            if ($dispatchCreatedEvent) {
                ContactCreated::dispatch($contact);
            }

            return $contact;
        }

        $exists = Contact::withTrashed()->where($lookup)->exists();
        $contact = Contact::withTrashed()->updateOrCreate($lookup, array_merge($data, ['workspace_id' => $workspaceId]));

        // Restore soft-deleted contact so it appears in normal queries again.
        if ($contact->trashed()) {
            $contact->restore();
        }

        if (! $exists && $dispatchCreatedEvent) {
            ContactCreated::dispatch($contact);
        }

        return $contact;
    }

    /** The six Personal Details fields import must never silently blank out — see normalizeProfileRow(). */
    private const PERSONAL_DETAIL_FIELDS = ['gender', 'birthday', 'anniversary_date', 'city', 'state', 'postal_code'];

    /**
     * The three marketing opt-in columns import can now set, mapped to their
     * error-message label. `opt_in_email` is here deliberately — the bug
     * report this fixes is that Email opted in SILENTLY, from the database
     * column's own `DEFAULT true`, for every row that never touched it.
     *
     * @var array<string, string>
     */
    private const OPT_IN_FIELDS = [
        'opt_in_whatsapp' => 'WhatsApp Opt-in',
        'opt_in_sms' => 'SMS Opt-in',
        'opt_in_email' => 'Email Opt-in',
    ];

    /**
     * Bulk import from an array of rows.
     * Returns ['created' => int, 'updated' => int, 'skipped' => int, 'errors' => list<string>].
     *
     * ⚠️ $rowNumberOffset exists because the caller counts rows the way the
     * USER does. A CSV's first data row is file line 2 (line 1 is the header),
     * so "Row 2" in an error message has to mean the same row the admin sees
     * when they open the file — see ContactController::import().
     *
     * @param  string|null  $defaultCountry  ISO-3166-1 alpha-2 chosen explicitly on the
     *                                       import screen. Null is legitimate and means
     *                                       "only fully-qualified +… numbers are accepted";
     *                                       it is never silently replaced with a guess.
     */
    public function bulkImport(int $workspaceId, array $rows, string $source = 'import', ?string $defaultCountry = null, int $rowNumberOffset = 1): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];

        foreach (array_values($rows) as $index => $row) {
            $rowNumber = $index + $rowNumberOffset;

            // A `Name` column (as opposed to separate First/Last Name columns)
            // is a legacy/alternate CSV header — see
            // ContactController::IMPORT_HEADER_MAP. It arrives here as the
            // pseudo-field `_full_name` so it can be split the same way the
            // XLSX grid path already splits its own combined `name` field —
            // one definition, splitFullName(), not two.
            //
            // ⚠️ THIS IS THE FIX for: "A file containing valid Name and Phone
            // must never create a contact whose displayed name and phone are
            // blank." Before this, a CSV with a `Name` header (no separate
            // First/Last Name columns) had NO recognized name column at all —
            // IMPORT_HEADER_MAP simply had no key for it — so the row's name
            // was silently dropped while its phone imported fine.
            if (array_key_exists('_full_name', $row)) {
                $fullName = trim((string) $row['_full_name']);
                unset($row['_full_name']);

                if ($fullName !== '' && ! array_key_exists('first_name', $row) && ! array_key_exists('last_name', $row)) {
                    ['first' => $row['first_name'], 'last' => $row['last_name']] = self::splitFullName($fullName);
                }
            }

            // Phone first: it is the contact's identity, so a row whose phone
            // cannot be normalized must never reach upsert() and create a
            // contact keyed on a non-canonical string.
            //
            // Only when a phone was actually supplied — a row carrying just an
            // email is valid here and always has been (upsert() falls back to
            // an email lookup), so requiring a phone would be a new rejection
            // this change has no mandate to add.
            if (array_key_exists('phone_e164', $row) && trim((string) $row['phone_e164']) !== '') {
                ['phone' => $phone, 'error' => $phoneError] = PhoneNumber::normalizeForImport($row['phone_e164'], $defaultCountry);

                if ($phoneError !== null) {
                    $stats['errors'][] = "Row {$rowNumber}: {$phoneError}";
                    $stats['skipped']++;

                    continue;
                }

                $row['phone_e164'] = $phone;
            } elseif (array_key_exists('phone_e164', $row)) {
                // Column PRESENT but blank. Must not become a raw '' that
                // silently blanks an existing contact's phone on update —
                // unset it so it means the same as "column omitted",
                // matching the Personal Details "blank means don't touch"
                // rule. This was the same class of leak already fixed for
                // the opt-in columns (see the array_diff_key note below):
                // normalizeProfileRow() passes any key it does not own
                // straight through untouched, so an un-unset blank string
                // here would ride along into the write.
                unset($row['phone_e164']);
            }

            // Email: same shape as phone — normalize/validate BEFORE
            // normalizeProfileRow() sees it, write the clean (trimmed,
            // lowercased) value back so it passes through untouched, or
            // unset a present-but-blank cell so it can never erase an
            // existing email on update.
            if (array_key_exists('email', $row) && trim((string) $row['email']) !== '') {
                ['email' => $email, 'error' => $emailError] = self::normalizeEmailInput((string) $row['email']);

                if ($emailError !== null) {
                    $stats['errors'][] = "Row {$rowNumber}: {$emailError}";
                    $stats['skipped']++;

                    continue;
                }

                $row['email'] = $email;
            } elseif (array_key_exists('email', $row)) {
                unset($row['email']);
            }

            ['data' => $normalized, 'errors' => $rowErrors] = $this->normalizeProfileRow($row);

            if ($rowErrors !== []) {
                // Whole-row rejection, no partial write — the same
                // all-or-nothing semantics as the dashboard/API validators
                // (one bad field fails the whole request), so a row never
                // ends up with SOME of its Personal Details applied and
                // others silently dropped because of a typo elsewhere.
                foreach ($rowErrors as $error) {
                    $stats['errors'][] = "Row {$rowNumber}: {$error}";
                }
                $stats['skipped']++;

                continue;
            }

            // ⚠️ IDENTITY REQUIRED. Without this, a row with no phone AND no
            // email — e.g. a blank trailing row, or a row where every
            // recognized column happened to be empty — reached upsert() with
            // an empty $lookup, which takes upsert()'s OTHER branch:
            // Contact::create() with no uniqueness check at all. That is how
            // a completely blank, unusable contact record could be created
            // with zero error reported. Checked here, before upsert(), for
            // BOTH import paths.
            if (empty($normalized['phone_e164']) && empty($normalized['email'])) {
                $stats['errors'][] = "Row {$rowNumber}: A phone number or email address is required to identify this contact.";
                $stats['skipped']++;

                continue;
            }

            // ⚠️ resolveContactIdentity(), not a raw where()->orWhere() lookup.
            // Phone-then-email is not enough on its own once a row can carry
            // BOTH: this is what catches "this email already belongs to a
            // DIFFERENT contact" and "this email matches more than one
            // existing contact" and rejects the row rather than silently
            // merging two people or guessing.
            $identity = $this->resolveContactIdentity(
                $workspaceId,
                $normalized['phone_e164'] ?? null,
                $normalized['email'] ?? null
            );

            if ($identity['error'] !== null) {
                $stats['errors'][] = "Row {$rowNumber}: {$identity['error']}";
                $stats['skipped']++;

                continue;
            }

            $existing = $identity['contact'];

            ['data' => $optInData, 'errors' => $optInErrors] = self::resolveOptInFields($row, isNewContact: $existing === null);

            if ($optInErrors !== []) {
                // Same whole-row, no-partial-write rule as Personal Details —
                // requirement 3: "Invalid consent value must skip the entire
                // row with a visible row-specific error."
                foreach ($optInErrors as $error) {
                    $stats['errors'][] = "Row {$rowNumber}: {$error}";
                }
                $stats['skipped']++;

                continue;
            }

            try {
                // ⚠️ dispatchCreatedEvent: false — MANDATORY for this path, not an
                // optimization. A CSV import represents historical/contact-list
                // data, never a live event. Without this flag, ContactCreated
                // fires AutomationTriggerListener::handleContactCreated() (any
                // `contact.created` automation — welcome messages, WhatsApp/SMS/
                // email sends) and DispatchOutboundWebhookListener::handleContactCreated()
                // (outbound `contact.created` webhooks) for every row, turning a
                // bulk historical import into a mass live-send event. This is the
                // SAME convention already used by SyncStoreCustomersJob and
                // BackfillStoreOrdersJob for e-commerce bulk sync/backfill — see
                // upsert()'s own docblock, which already documented this exact
                // requirement before either import path here honoured it.
                // ⚠️ array_diff_key($normalized, self::OPT_IN_FIELDS) — NOT
                // $normalized as-is. normalizeProfileRow() passes every key
                // it does not own straight through UNTOUCHED (that is its own
                // documented contract), which means $normalized still carries
                // the RAW, unvalidated opt-in strings (e.g. '' for a blank
                // cell) exactly as they arrived in $row. Merging that in
                // would let a raw '' silently win over $optInData's correct
                // "leave this alone" (an omitted key) — array_merge takes
                // the LAST array's value for a shared key, and $optInData
                // omits the key entirely when there is nothing to change, so
                // the untouched raw value from $normalized would pass
                // straight through to upsert(). MySQL then rejects '' for a
                // boolean column with a QueryException that the catch below
                // swallows — which silently skipped the WHOLE row, City and
                // all, not just the opt-in fields. Caught by testing the
                // actual persisted row, not the dispatched call.
                $contact = $this->upsert(
                    $workspaceId,
                    array_merge(['source' => $source], array_diff_key($normalized, self::OPT_IN_FIELDS), $optInData),
                    dispatchCreatedEvent: false
                );

                if ($existing) {
                    $stats['updated']++;
                } else {
                    $stats['created']++;
                }
            } catch (\Throwable $e) {
                // ⚠️ Logged, not silently dropped. This branch is for
                // GENUINELY UNEXPECTED failures (a DB constraint violation,
                // a connection error) — every EXPECTED rejection (bad phone,
                // bad gender, bad opt-in value, missing identity) already has
                // its own $stats['errors'][] entry earlier in this loop and
                // never reaches here. An exception that DOES reach here with
                // no log line is invisible in exactly the way the original
                // bug report's "0 created, 0 updated, N skipped, no reason"
                // was — see array_diff_key($normalized, self::OPT_IN_FIELDS)
                // above for the bug this caught in development.
                Log::error('contact_import.row_failed', [
                    'row' => $rowNumber,
                    'workspace_id' => $workspaceId,
                    'error' => $e->getMessage(),
                ]);
                $stats['skipped']++;
            }
        }

        return $stats;
    }

    /**
     * Resolve the three opt-in columns for ONE row into what `upsert()`
     * should write, given whether this row targets a NEW contact or an
     * EXISTING one — the two cases need genuinely different behaviour, which
     * is why this is not folded into normalizeProfileRow()'s uniform
     * "blank means don't touch" rule:
     *
     *   NEW contact      omitted/blank -> explicit `false` (written).
     *                    `contacts.opt_in_email` DEFAULTs to `true` at the
     *                    database level; if this method merely omitted the
     *                    key the way normalizeProfileRow() does, that
     *                    default would leak straight through on every row
     *                    that doesn't mention Email Opt-in — which is
     *                    exactly the bug this fixes. So a NEW contact's
     *                    opt-ins are ALWAYS written explicitly, never left
     *                    to fall through to a column default.
     *
     *   EXISTING contact omitted/blank -> key not set at all, so
     *                    upsert()'s updateOrCreate() leaves the current
     *                    value untouched. Re-importing a file that doesn't
     *                    mention consent must never silently revoke — or
     *                    silently grant — it.
     *
     * @param  array<string, mixed>  $row
     * @return array{data: array<string, bool>, errors: list<string>}
     */
    private static function resolveOptInFields(array $row, bool $isNewContact): array
    {
        $data = [];
        $errors = [];

        foreach (self::OPT_IN_FIELDS as $field => $label) {
            if (! array_key_exists($field, $row)) {
                if ($isNewContact) {
                    $data[$field] = false;
                }

                continue;
            }

            $raw = trim((string) $row[$field]);

            if ($raw === '') {
                if ($isNewContact) {
                    $data[$field] = false;
                }

                continue;
            }

            $parsed = self::normalizeOptInInput($raw);

            if ($parsed === null) {
                $errors[] = "{$label} \"{$raw}\" is not recognized. Use Yes/No, True/False, or 1/0.";

                continue;
            }

            $data[$field] = $parsed;
        }

        return ['data' => $data, 'errors' => $errors];
    }

    /**
     * Case-insensitive, explicit values only — requirement: "Yes / No,
     * True / False, 1 / 0". Deliberately does NOT accept anything looser
     * (e.g. "y", "on", "subscribed") — an ambiguous marketing-consent value
     * must be rejected and asked about again, never guessed.
     */
    private static function normalizeOptInInput(string $raw): ?bool
    {
        return match (mb_strtolower($raw)) {
            'yes', 'true', '1' => true,
            'no', 'false', '0' => false,
            default => null,
        };
    }

    /**
     * Split one combined "Name" value into first/last, EXACTLY the way
     * importGridRows() already split its own `name` field — extracted here so
     * there is one definition, used by both import paths, rather than the
     * XLSX grid quietly having its own copy of this logic (see CLAUDE.md:
     * grep for other definitions of the same concept before writing a new
     * one — this refactor is that grep).
     *
     * @return array{first: string|null, last: string|null}
     */
    private static function splitFullName(string $name): array
    {
        $parts = preg_split('/\s+/u', $name, 2) ?: [];

        return ['first' => $parts[0] ?? null, 'last' => $parts[1] ?? null];
    }

    /**
     * Trims, lowercases (for matching — email addresses are
     * case-insensitive by convention, and two spellings of one address must
     * never become two contacts, the exact same reasoning PhoneNumber
     * applies to phone digits), and strictly validates one Email cell.
     *
     * @return array{email: string|null, error: string|null}
     */
    private static function normalizeEmailInput(string $raw): array
    {
        $trimmed = mb_strtolower(trim($raw));

        if ($trimmed === '') {
            return ['email' => null, 'error' => null];
        }

        if (filter_var($trimmed, FILTER_VALIDATE_EMAIL) === false) {
            return ['email' => null, 'error' => "Email \"{$raw}\" is not a valid email address."];
        }

        return ['email' => $trimmed, 'error' => null];
    }

    /**
     * The single place implementing the identity rules for a row carrying a
     * (possibly absent) phone and a (possibly absent, already-normalized)
     * email:
     *
     *   phone only          match/create by phone. Unchanged from before
     *                       email support existed.
     *
     *   email only          match by NORMALIZED email within the workspace.
     *                       Zero matches -> new contact. Exactly one -> that
     *                       contact. MORE than one (the workspace already
     *                       has duplicate rows sharing that email) ->
     *                       reject rather than guess which one the row
     *                       means.
     *
     *   phone AND email     resolved by PHONE FIRST. If the email ALSO
     *                       belongs to some contact other than the
     *                       phone-resolved one (or to ANY contact, when the
     *                       phone resolves to a brand-new one) -> reject as
     *                       an identity conflict. Two contacts are never
     *                       silently merged just because one row happened to
     *                       name both of them.
     *
     * Deliberately does NOT call upsert() or write anything — this is a
     * read-only pre-flight check. Once it returns without an error, the
     * caller's own upsert() call (via its existing, simpler phone-then-email
     * lookup) is guaranteed to land on the exact same row this method
     * identified, because every ambiguous or conflicting case was already
     * rejected here.
     *
     * @return array{contact: Contact|null, error: string|null}
     */
    private function resolveContactIdentity(int $workspaceId, ?string $phone, ?string $email): array
    {
        $phoneMatch = $phone !== null
            ? Contact::where('workspace_id', $workspaceId)->where('phone_e164', $phone)->first()
            : null;

        if ($email === null) {
            return ['contact' => $phoneMatch, 'error' => null];
        }

        // Workspace-scoped, same as every other identity lookup in this
        // class — one workspace must never find or update another
        // workspace's contact via a shared email address.
        $emailMatches = Contact::where('workspace_id', $workspaceId)->where('email', $email)->get();

        if ($phone !== null) {
            $conflicting = $emailMatches->contains(fn (Contact $c) => $phoneMatch === null || $c->id !== $phoneMatch->id);

            if ($conflicting) {
                return [
                    'contact' => null,
                    'error' => "Email \"{$email}\" already belongs to a different contact in this workspace; refusing to merge two contacts.",
                ];
            }

            return ['contact' => $phoneMatch, 'error' => null];
        }

        // Email-only row.
        if ($emailMatches->count() > 1) {
            return [
                'contact' => null,
                'error' => "Email \"{$email}\" matches more than one existing contact in this workspace; cannot determine which one to update.",
            ];
        }

        return ['contact' => $emailMatches->first(), 'error' => null];
    }

    /**
     * Validates and normalizes the six Personal Details fields on one
     * import row. Every other key in $row (phone_e164, email, first_name,
     * last_name, and — for the Bulk Import grid path — tag_id/segment_id)
     * passes through completely untouched — this method's only job is the
     * friendlier accepted input for these six fields (gender labels, strict
     * dates) and the "blank means don't touch" rule. Shared by both the CSV
     * import path (string values only) and the Bulk Import grid path
     * (tag_id/segment_id are ints) — hence `mixed`, not `string`, values.
     *
     * @param  array<string, mixed>  $row
     * @return array{data: array<string, mixed>, errors: list<string>}
     */
    private function normalizeProfileRow(array $row): array
    {
        $data = $row;
        $errors = [];

        foreach (self::PERSONAL_DETAIL_FIELDS as $field) {
            if (! array_key_exists($field, $data)) {
                // Column omitted from the file entirely — never touch the
                // existing Contact value for it.
                continue;
            }

            $raw = trim((string) $data[$field]);

            if ($raw === '') {
                // A blank cell in a PRESENT column must also leave the
                // existing value unchanged, never overwrite it with blank —
                // so the key is removed, not set to ''.
                unset($data[$field]);

                continue;
            }

            if ($field === 'gender') {
                $normalized = $this->normalizeGenderInput($raw);
                if ($normalized === null) {
                    $errors[] = 'Gender "'.$raw.'" is not recognized. Use Male, Female, Non-binary / Other, or Prefer not to say.';
                } else {
                    $data['gender'] = $normalized;
                }

                continue;
            }

            if ($field === 'birthday' || $field === 'anniversary_date') {
                $error = $this->validateProfileDateInput($field, $raw);
                if ($error !== null) {
                    $errors[] = $error;
                } else {
                    $data[$field] = $raw;
                }

                continue;
            }

            // city / state / postal_code: plain trimmed strings.
            $data[$field] = $raw;
        }

        return ['data' => $data, 'errors' => $errors];
    }

    /** Accepts a canonical Contact::GENDERS value OR its Contact::GENDER_LABELS label, case-insensitively. */
    private function normalizeGenderInput(string $raw): ?string
    {
        $lower = mb_strtolower($raw);

        if (in_array($lower, Contact::GENDERS, true)) {
            return $lower;
        }

        foreach (Contact::GENDER_LABELS as $canonical => $label) {
            if ($lower === mb_strtolower($label)) {
                return $canonical;
            }
        }

        return null;
    }

    /**
     * Strict YYYY-MM-DD only — never guesses between dd/mm/yyyy and
     * mm/dd/yyyy. Same range rules as
     * ContactController::profileValidationRules() /
     * ContactApiController::profileValidationRules(): birthday no earlier
     * than 1900-01-01, neither field ever in the future.
     */
    private function validateProfileDateInput(string $field, string $raw): ?string
    {
        $label = $field === 'birthday' ? 'Birthday' : 'Anniversary Date';

        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m)) {
            return "{$label} \"{$raw}\" must be in YYYY-MM-DD format.";
        }

        [, $year, $month, $day] = $m;
        if (! checkdate((int) $month, (int) $day, (int) $year)) {
            return "{$label} \"{$raw}\" is not a valid date.";
        }

        $date = \DateTime::createFromFormat('!Y-m-d', $raw);

        if ($date > new \DateTime('today')) {
            return "{$label} \"{$raw}\" cannot be in the future.";
        }

        if ($field === 'birthday' && $date < new \DateTime('1900-01-01')) {
            return "Birthday \"{$raw}\" cannot be before 1900-01-01.";
        }

        return null;
    }

    /**
     * Import contacts from spreadsheet-style rows — the backend for the
     * XLSX/ExcelJS Bulk Import grid (bulkImportExcel.js's matrixToPayload()).
     * Shares its Personal Details validation/normalization (gender labels,
     * strict dates, blank-means-don't-touch) with the CSV import path via
     * normalizeProfileRow(), its phone normalization via
     * PhoneNumber::normalizeForImport(), its email normalization via
     * normalizeEmailInput(), and its identity resolution via
     * resolveContactIdentity() — so the two import surfaces can never
     * silently diverge on what counts as a valid Gender, date, phone number,
     * email address, or contact identity.
     *
     * ⚠️ Phone is NO LONGER MANDATORY here. It was, until email support was
     * added — every row required a phone because that was the grid's only
     * identity key. An email-only row is now valid, exactly like the CSV
     * path has always allowed (upsert() falling back to an email lookup).
     *
     * A row's phone may be written either fully-qualified (+91…) or as local
     * digits; local digits require $defaultCountry. The STORED value is always
     * canonical E.164 — the `(workspace_id, phone_e164)` unique key means the
     * stored string is the contact's identity, so that is never relaxed.
     *
     * @param  array<int, array{name?: string|null, phone_e164?: string|null, email?: string|null, tag_id?: int|null, segment_id?: int|null, gender?: string|null, birthday?: string|null, anniversary_date?: string|null, city?: string|null, state?: string|null, postal_code?: string|null, opt_in_whatsapp?: string|null, opt_in_sms?: string|null, opt_in_email?: string|null, _row_number?: int}>  $rows
     * @param  string|null  $defaultCountry  ISO-3166-1 alpha-2 chosen explicitly on the
     *                                       import screen; never defaulted to a guess.
     * @return array{created: int, updated: int, skipped: int, errors: list<string>}
     */
    public function importGridRows(int $workspaceId, array $rows, string $source = 'import', ?string $defaultCountry = null): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];
        $segmentIdsTouched = [];

        foreach (array_values($rows) as $index => $row) {
            $rowNumber = $row['_row_number'] ?? ($index + 1);
            unset($row['_row_number']);

            // ⚠️ CONDITIONAL, not unconditional. This used to call
            // PhoneNumber::normalizeForImport() unconditionally, which
            // REJECTS a blank phone outright ("Phone number is required.") —
            // correct back when phone was the grid's only identity key, but
            // it would reject every valid email-only row outright the moment
            // email support was added. Matches bulkImport()'s own
            // "only when a phone was actually supplied" phone block exactly.
            $phone = null;
            $rawPhone = isset($row['phone_e164']) ? trim((string) $row['phone_e164']) : '';

            if ($rawPhone !== '') {
                ['phone' => $phone, 'error' => $phoneError] = PhoneNumber::normalizeForImport($rawPhone, $defaultCountry);

                if ($phoneError !== null) {
                    $stats['errors'][] = "Row {$rowNumber}: {$phoneError}";
                    $stats['skipped']++;

                    continue;
                }
            }

            $email = null;
            $rawEmail = isset($row['email']) ? trim((string) $row['email']) : '';

            if ($rawEmail !== '') {
                ['email' => $email, 'error' => $emailError] = self::normalizeEmailInput($rawEmail);

                if ($emailError !== null) {
                    $stats['errors'][] = "Row {$rowNumber}: {$emailError}";
                    $stats['skipped']++;

                    continue;
                }
            }

            // ⚠️ IDENTITY REQUIRED — same rule and same reasoning as
            // bulkImport()'s identical check. Reachable here now that phone
            // is no longer mandatory: an all-blank row (or one with only
            // Personal Details filled in) must be rejected by name, not
            // silently create a blank, unusable contact.
            if ($phone === null && $email === null) {
                $stats['errors'][] = "Row {$rowNumber}: A phone number or email address is required to identify this contact.";
                $stats['skipped']++;

                continue;
            }

            ['data' => $profileData, 'errors' => $rowErrors] = $this->normalizeProfileRow($row);

            if ($rowErrors !== []) {
                // Whole-row rejection, no partial write — identical
                // semantics to the CSV import path (see bulkImport()).
                foreach ($rowErrors as $error) {
                    $stats['errors'][] = "Row {$rowNumber}: {$error}";
                }
                $stats['skipped']++;

                continue;
            }

            // ⚠️ resolveContactIdentity(), not a raw phone-only where(). See
            // the identical comment in bulkImport() — this is what catches
            // "this email already belongs to a different contact" and
            // "this email matches more than one contact" and rejects the row
            // rather than silently merging two people.
            $identity = $this->resolveContactIdentity($workspaceId, $phone, $email);

            if ($identity['error'] !== null) {
                $stats['errors'][] = "Row {$rowNumber}: {$identity['error']}";
                $stats['skipped']++;

                continue;
            }

            $existing = $identity['contact'];

            // Shared with bulkImport()'s `_full_name` handling — one
            // definition (splitFullName()), so the CSV path's `Name` column
            // and the grid's own `name` column can never silently diverge on
            // how a combined name is split.
            $name = isset($row['name']) ? trim((string) $row['name']) : '';
            $firstName = null;
            $lastName = null;
            if ($name !== '') {
                ['first' => $firstName, 'last' => $lastName] = self::splitFullName($name);
            }

            ['data' => $optInData, 'errors' => $optInErrors] = self::resolveOptInFields($row, isNewContact: $existing === null);

            if ($optInErrors !== []) {
                // Same whole-row, no-partial-write rule as bulkImport() and as
                // Personal Details above — requirement 3.
                foreach ($optInErrors as $error) {
                    $stats['errors'][] = "Row {$rowNumber}: {$error}";
                }
                $stats['skipped']++;

                continue;
            }

            try {
                // ⚠️ dispatchCreatedEvent: false — see the identical comment in
                // bulkImport() above. The XLSX/Handsontable grid is the same
                // historical bulk-import operation as the CSV path and must
                // suppress the exact same automation + outbound-webhook effects.
                //
                // ⚠️ phone_e164 / email are added to the merge ONLY when this
                // row actually supplied one — never as a bare key that could
                // be null/blank. A blank Phone (or Email) cell on a row
                // identified by the OTHER field must never erase what the
                // matched contact already has; omitting the key entirely is
                // what gives updateOrCreate() its "leave this alone" behaviour,
                // the same rule Personal Details and the opt-in columns follow.
                $identityData = array_filter([
                    'phone_e164' => $phone,
                    'email' => $email,
                ], fn ($v) => $v !== null);

                $contact = $this->upsert($workspaceId, array_merge([
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'source' => $source,
                ], $identityData, array_intersect_key($profileData, array_flip(self::PERSONAL_DETAIL_FIELDS)), $optInData), dispatchCreatedEvent: false);

                if ($existing) {
                    $stats['updated']++;
                } else {
                    $stats['created']++;
                }

                $tagId = isset($row['tag_id']) ? (int) $row['tag_id'] : 0;
                if ($tagId > 0 && ContactTag::where('workspace_id', $workspaceId)->whereKey($tagId)->exists()) {
                    $contact->tags()->syncWithoutDetaching([$tagId]);
                }

                $segmentId = isset($row['segment_id']) ? (int) $row['segment_id'] : 0;
                if ($segmentId > 0) {
                    $segment = Segment::where('workspace_id', $workspaceId)
                        ->whereKey($segmentId)
                        ->where('type', 'static')
                        ->first();
                    if ($segment) {
                        $contact->segments()->syncWithoutDetaching([$segment->id]);
                        $segmentIdsTouched[$segment->id] = true;
                    }
                }
            } catch (\Throwable $e) {
                // ⚠️ Logged, not silently dropped. This branch is for
                // GENUINELY UNEXPECTED failures (a DB constraint violation,
                // a connection error) — every EXPECTED rejection (bad phone,
                // bad gender, bad opt-in value, missing identity) already has
                // its own $stats['errors'][] entry earlier in this loop and
                // never reaches here. An exception that DOES reach here with
                // no log line is invisible in exactly the way the original
                // bug report's "0 created, 0 updated, N skipped, no reason"
                // was — see array_diff_key($normalized, self::OPT_IN_FIELDS)
                // above for the bug this caught in development.
                Log::error('contact_import.row_failed', [
                    'row' => $rowNumber,
                    'workspace_id' => $workspaceId,
                    'error' => $e->getMessage(),
                ]);
                $stats['skipped']++;
            }
        }

        foreach (array_keys($segmentIdsTouched) as $segmentId) {
            $segment = Segment::query()->find($segmentId);
            if ($segment) {
                $segment->update(['contact_count' => $segment->contacts()->count()]);
            }
        }

        return $stats;
    }

    /**
     * Sync a contact's avatar from an external URL (WhatsApp, Instagram, Messenger profile pics).
     * Only updates if the contact has no manually-uploaded avatar (non-http stored path),
     * or if force=true.
     */
    public function syncAvatarFromUrl(Contact $contact, string $url, bool $force = false): void
    {
        if (! $force && $contact->avatar && ! str_starts_with($contact->avatar, 'http')) {
            // Contact has a manually uploaded avatar — don't overwrite
            return;
        }

        // Store the external URL directly (lightweight — no download needed for display)
        if ($contact->avatar !== $url) {
            $contact->update(['avatar' => $url]);
        }
    }

    /**
     * Download an external avatar URL and store it locally on the public disk.
     * Use this when you need a permanent local copy (e.g. WhatsApp CDN URLs expire).
     */
    public function downloadAndStoreAvatar(Contact $contact, string $url): void
    {
        try {
            $response = Http::timeout(10)->get($url);
            if (! $response->successful()) {
                return;
            }

            $contentType = $response->header('Content-Type') ?? 'image/jpeg';
            $ext = match (true) {
                str_contains($contentType, 'png') => 'png',
                str_contains($contentType, 'webp') => 'webp',
                str_contains($contentType, 'gif') => 'gif',
                default => 'jpg',
            };

            // Delete old stored avatar
            if ($contact->avatar && ! str_starts_with($contact->avatar, 'http')) {
                $this->storageManager->disk()->delete($contact->avatar);
            }

            $rawPath = 'contact-avatars/'.$contact->id.'_'.time().'.'.$ext;
            $path = $this->storageManager->prefixedPath($rawPath);
            $this->storageManager->disk()->put($path, $response->body());
            $contact->update(['avatar' => $path]);
        } catch (\Throwable) {
            // Avatar sync is non-critical; silently fail
        }
    }

    /** Export contacts for a workspace as array of arrays. */
    public function export(int $workspaceId): Collection
    {
        return Contact::where('workspace_id', $workspaceId)
            ->with('tags')
            ->get()
            ->map(fn (Contact $c) => [
                'phone' => $c->phone_e164,
                'email' => $c->email,
                'first_name' => $c->first_name,
                'last_name' => $c->last_name,
                'country' => $c->country,
                'language' => $c->language,
                'opt_in_wa' => $c->opt_in_whatsapp ? 'yes' : 'no',
                'opt_in_sms' => $c->opt_in_sms ? 'yes' : 'no',
                'opt_in_email' => $c->opt_in_email ? 'yes' : 'no',
                'tags' => $c->tags->pluck('name')->implode(','),
                'source' => $c->source,
                'created_at' => $c->created_at?->toISOString(),
            ]);
    }
}
