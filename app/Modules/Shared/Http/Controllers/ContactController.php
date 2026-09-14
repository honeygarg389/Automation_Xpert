<?php

namespace App\Modules\Shared\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\ContactTag;
use App\Modules\Shared\Models\Segment;
use App\Modules\Shared\Services\ContactService;
use App\Services\StorageManager;
use App\Support\Demo;
use App\Support\PhoneNumber;
use App\Support\WorkspaceContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ContactController extends Controller
{
    public function __construct(
        private ContactService $contactService,
        private StorageManager $storageManager,
    ) {}

    public function index(Request $request): Response
    {
        $workspaceId = WorkspaceContext::id() ?? $request->user()->workspace_id;

        $contacts = Contact::where('workspace_id', $workspaceId)
            ->with('tags')
            ->when($request->search, fn ($q) => $q->where(function ($q) use ($request) {
                $q->where('first_name', 'like', '%'.$request->search.'%')
                    ->orWhere('last_name', 'like', '%'.$request->search.'%')
                    ->orWhere('phone_e164', 'like', '%'.$request->search.'%')
                    ->orWhere('email', 'like', '%'.$request->search.'%');
            }))
            ->when($request->tag, fn ($q) => $q->whereHas('tags', fn ($q) => $q->where('name', $request->tag)))
            ->latest()
            ->paginate(50)
            ->withQueryString();

        $tags = ContactTag::where('workspace_id', $workspaceId)->orderBy('name')->get();
        $segments = Segment::where('workspace_id', $workspaceId)->where('type', 'static')->orderBy('name')->get(['id', 'name']);

        return Inertia::render('Contacts/Index', [
            'contacts' => $contacts,
            'tags' => $tags,
            'segments' => $segments,
            'filters' => $request->only('search', 'tag'),
            'phoneCountries' => PhoneNumber::options(),
            'defaultPhoneCountry' => self::suggestedPhoneCountry(),
        ]);
    }

    /**
     * The country to preselect in the import screens' "Default phone country"
     * control.
     *
     * ⚠️ ALWAYS NULL TODAY, and that is a measured answer rather than a
     * placeholder. The requirement is to preselect the workspace/client's known
     * country "only if real codebase data supports it" — it does not.
     * `workspaces` carries only (owner_id, client_id, name, default_locale,
     * currency_code, client_mode) and `clients` only
     * (…, base_currency, currency_symbol, currency_position, phone, address);
     * neither has a country column, and no settings table holds one. Checked
     * across every migration, not inferred.
     *
     * The near-misses were considered and rejected on purpose:
     *   - `clients.base_currency` — currency is not country (USD is legal
     *     tender in several, EUR in twenty), and a wrong guess here silently
     *     rewrites every local number in the file.
     *   - `workspaces.default_locale` — 'en' names a language, not a place.
     *   - `clients.phone` / `clients.address` — free-text, never validated, and
     *     parsing a country out of prose is exactly the kind of guess
     *     requirement 4 forbids.
     *
     * So the control opens unselected and the user makes a conscious choice.
     * This method is the single seam to change if a real country field is ever
     * added — both import surfaces read it, so neither can drift.
     *
     * ⚠️ Return type is `null`, not `?string`. PHPStan (level 6) correctly
     * flagged an unconditional `return null;` inside a `?string` signature as
     * an unused type — the honest fix is to make the signature match what the
     * method actually does today, not to suppress the check. WIDEN this back
     * to `?string` in the same commit that gives it a real country to return;
     * the type change at that point is the marker that the seam was used.
     */
    private static function suggestedPhoneCountry(): null
    {
        return null;
    }

    public function bulkImport(Request $request): Response
    {
        return Inertia::render('Contacts/BulkImport', $this->bulkImportProps($request));
    }

    /**
     * @return array{tags: Collection, segments: Collection, phoneCountries: list<array<string, string>>, defaultPhoneCountry: string|null}
     */
    private function bulkImportProps(Request $request): array
    {
        $workspaceId = WorkspaceContext::id() ?? $request->user()->workspace_id;

        return [
            'tags' => ContactTag::where('workspace_id', $workspaceId)->orderBy('name')->get(),
            'segments' => Segment::where('workspace_id', $workspaceId)
                ->where('type', 'static')
                ->orderBy('name')
                ->get(['id', 'name']),
            // Same source as Contacts/Index so the CSV and XLSX screens can
            // never offer different country lists or different preselections.
            'phoneCountries' => PhoneNumber::options(),
            'defaultPhoneCountry' => self::suggestedPhoneCountry(),
        ];
    }

    public function show(Request $request, Contact $contact): Response
    {
        $this->authoriseContact($request, $contact);

        $workspaceId = WorkspaceContext::id() ?? $request->user()->workspace_id;
        $contact->load(['tags', 'segments', 'conversations' => fn ($q) => $q->with(['messages' => fn ($q) => $q->latest('sent_at')->limit(5)])->latest('last_message_at')->limit(10)]);

        $staticSegments = Segment::where('workspace_id', $workspaceId)->where('type', 'static')->orderBy('name')->get(['id', 'name']);

        return Inertia::render('Contacts/Show', [
            'contact' => $contact,
            'staticSegments' => $staticSegments,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $workspaceId = WorkspaceContext::id() ?? $request->user()->workspace_id;
        $validated = $request->validate([
            'phone_e164' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:191'],
            'first_name' => ['nullable', 'string', 'max:128'],
            'last_name' => ['nullable', 'string', 'max:128'],
            'country' => ['nullable', 'string', 'max:4'],
            ...$this->profileValidationRules(),
            'language' => ['nullable', 'string', 'max:8'],
            'opt_in_whatsapp' => ['boolean'],
            'opt_in_sms' => ['boolean'],
            'opt_in_email' => ['boolean'],
            'segment_ids' => ['nullable', 'array'],
            'segment_ids.*' => ['integer', Rule::exists('segments', 'id')->where(fn ($q) => $q->where('workspace_id', $workspaceId)->where('type', 'static'))],
        ]);

        $segmentIds = $validated['segment_ids'] ?? [];
        unset($validated['segment_ids']);

        $contact = $this->contactService->upsert($workspaceId, array_merge($validated, ['source' => 'manual']));

        if ($segmentIds) {
            $contact->segments()->syncWithoutDetaching($segmentIds);
            Segment::whereIn('id', $segmentIds)->each(fn ($s) => $s->update(['contact_count' => $s->contacts()->count()]));
        }

        return back()->with('success', 'Contact saved.');
    }

    public function update(Request $request, Contact $contact): RedirectResponse
    {
        $this->authoriseContact($request, $contact);
        $workspaceId = WorkspaceContext::id() ?? $request->user()->workspace_id;
        $validated = $request->validate([
            'first_name' => ['nullable', 'string', 'max:128'],
            'last_name' => ['nullable', 'string', 'max:128'],
            'email' => ['nullable', 'email', 'max:191'],
            'country' => ['nullable', 'string', 'max:4'],
            ...$this->profileValidationRules(),
            'language' => ['nullable', 'string', 'max:8'],
            'opt_in_whatsapp' => ['boolean'],
            'opt_in_sms' => ['boolean'],
            'opt_in_email' => ['boolean'],
            'custom_fields' => ['nullable', 'array'],
            'segment_ids' => ['nullable', 'array'],
            'segment_ids.*' => ['integer', Rule::exists('segments', 'id')->where(fn ($q) => $q->where('workspace_id', $workspaceId)->where('type', 'static'))],
        ]);

        $segmentIds = $validated['segment_ids'] ?? null;
        unset($validated['segment_ids']);

        $contact->update($validated);

        if ($segmentIds !== null) {
            $oldSegmentIds = $contact->segments()->where('type', 'static')->pluck('segments.id')->toArray();
            $contact->segments()->sync($segmentIds);
            $affectedIds = array_unique(array_merge($oldSegmentIds, $segmentIds));
            Segment::whereIn('id', $affectedIds)->each(fn ($s) => $s->update(['contact_count' => $s->contacts()->count()]));
        }

        return back()->with('success', 'Contact updated.');
    }

    public function destroy(Request $request, Contact $contact): RedirectResponse
    {
        $this->authoriseContact($request, $contact);
        $contact->delete();

        return back()->with('success', 'Contact deleted.');
    }

    public function uploadAvatar(Request $request, Contact $contact): RedirectResponse
    {
        $this->authoriseContact($request, $contact);
        $request->validate([
            'avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:2048'],
        ]);

        // Delete old stored avatar if it's not an external URL
        if ($contact->avatar && ! str_starts_with($contact->avatar, 'http')) {
            $this->storageManager->disk()->delete($contact->avatar);
        }

        $file = $request->file('avatar');
        $path = $this->storageManager->prefixedPath('contact-avatars/'.$file->hashName());
        $this->storageManager->disk()->putFileAs('contact-avatars', $file, basename($path));
        $contact->update(['avatar' => $path]);

        return back()->with('success', 'Avatar updated.');
    }

    public function deleteAvatar(Request $request, Contact $contact): RedirectResponse
    {
        $this->authoriseContact($request, $contact);

        if ($contact->avatar && ! str_starts_with($contact->avatar, 'http')) {
            $this->storageManager->disk()->delete($contact->avatar);
        }

        $contact->update(['avatar' => null]);

        return back()->with('success', 'Avatar removed.');
    }

    /**
     * Maps exact exported CSV header text (case-insensitive, trimmed) to the
     * Contact column bulkImport() should write.
     *
     * ⚠️ EXACT MATCH ONLY — this is a lowercased-string lookup, not a fuzzy
     * substring match like the XLSX grid's headerIndex(). A sample header
     * with extra hint text (e.g. "Birthday (YYYY-MM-DD)") needs its OWN
     * entry here — it will not match the plain "birthday" key by containment
     * the way it would on the XLSX side.
     *
     * ⚠️ Still does NOT map Tags / Created At, even though export() prints
     * both: neither has ever been written by a CSV import, and nothing in
     * this change's scope asks for that. Opt-in WhatsApp / SMS / Email WERE
     * in that same "deliberately not mapped" list until this change — that
     * is the bug this fixes: import silently never touched them, so a new
     * contact's Email Opt-in fell through to the `contacts.opt_in_email`
     * column's own `DEFAULT true` while WhatsApp/SMS fell through to their
     * `DEFAULT false` — a phone-number-shaped input row appeared to "opt in
     * to Email" it never asked for. See ContactService::OPT_IN_FIELDS.
     *
     * `name` (combined) is a legacy/alternate header, recognized alongside
     * the separate `first name`/`last name` columns export() actually
     * prints — see ContactService::splitFullName(). Without this, a file
     * using a single Name column (which is what the XLSX grid itself calls
     * its own name column) had no recognized name header at all: the row's
     * phone imported fine and its name silently vanished.
     *
     * @var array<string, string>
     */
    private const IMPORT_HEADER_MAP = [
        'name' => '_full_name',
        'full name' => '_full_name',
        'contact name' => '_full_name',
        'first name' => 'first_name',
        'last name' => 'last_name',
        'phone' => 'phone_e164',
        'email' => 'email',
        'email (optional)' => 'email',
        'email address' => 'email',
        'gender' => 'gender',
        'birthday' => 'birthday',
        'birthday (yyyy-mm-dd)' => 'birthday',
        'anniversary date' => 'anniversary_date',
        'anniversary date (yyyy-mm-dd)' => 'anniversary_date',
        'city' => 'city',
        'state' => 'state',
        'postal code / pin code' => 'postal_code',
        // Canonical (current sample + export) header, plus the pre-existing
        // export casing as a backward-compatible alias for anyone re-importing
        // a file exported before this change.
        'whatsapp opt-in' => 'opt_in_whatsapp',
        'opt-in whatsapp' => 'opt_in_whatsapp',
        'sms opt-in' => 'opt_in_sms',
        'opt-in sms' => 'opt_in_sms',
        'email opt-in' => 'opt_in_email',
        'opt-in email' => 'opt_in_email',
    ];

    public function import(Request $request): RedirectResponse
    {
        $workspaceId = WorkspaceContext::id() ?? $request->user()->workspace_id;
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
            // Nullable on purpose: importing a file of fully-qualified +…
            // numbers needs no default country, and forcing a choice there
            // would be a new obstacle rather than a fix. Rows that DO need one
            // are refused individually, by name, in PhoneNumber.
            'default_country' => ['nullable', 'string', Rule::in(array_keys(PhoneNumber::COUNTRIES))],
        ]);

        $defaultCountry = $validated['default_country'] ?? null;

        $path = $request->file('file')->getRealPath();
        $handle = fopen($path, 'r');
        $headers = null;
        // CSV column index => Contact field key, built from whichever
        // recognized headers are actually present — column ORDER and which
        // columns are OMITTED entirely never matter; an omitted column is
        // simply never in this map, so bulkImport() never sees that key at
        // all and the existing value is left untouched on update.
        $columnMap = [];
        $data = [];
        $limit = 10000;

        while (($line = fgetcsv($handle)) !== false && count($data) < $limit) {
            if ($headers === null) {
                $headers = array_map('trim', $line);
                foreach ($headers as $index => $header) {
                    $mapped = self::IMPORT_HEADER_MAP[mb_strtolower($header)] ?? null;
                    if ($mapped !== null) {
                        $columnMap[$index] = $mapped;
                    }
                }

                continue;
            }
            if (count($line) === count($headers)) {
                $row = [];
                foreach ($columnMap as $index => $field) {
                    $row[$field] = trim((string) ($line[$index] ?? ''));
                }
                $data[] = $row;
            }
        }
        fclose($handle);

        if ($headers === null || empty($data)) {
            return back()->withErrors(['file' => 'The CSV file appears to be empty or has no valid rows.']);
        }

        // Offset 2, not 1: row 1 of the file is the header, so the first data
        // row is what the admin sees as row 2 when they open the CSV. An error
        // that says "Row 2" has to point at the row they can actually find.
        $stats = $this->contactService->bulkImport($workspaceId, $data, 'import', $defaultCountry, 2);

        $response = back()->with('success', "Imported: {$stats['created']} created, {$stats['updated']} updated, {$stats['skipped']} skipped.");

        if (! empty($stats['errors'])) {
            // Capped so a file with thousands of bad rows can't inflate the
            // session flash payload — the counts above already give the
            // total picture.
            $response = $response->with('import_errors', array_slice($stats['errors'], 0, 20));
        }

        return $response;
    }

    public function bulkStore(Request $request): Response
    {
        $workspaceId = (int) (WorkspaceContext::id() ?? $request->user()->workspace_id);

        $validated = $request->validate([
            'rows' => ['required', 'array', 'max:500'],
            'default_country' => ['nullable', 'string', Rule::in(array_keys(PhoneNumber::COUNTRIES))],
            'rows.*.name' => ['nullable', 'string', 'max:255'],
            // max:32, not 20: this is the value AS TYPED, before normalization
            // — "+91 86300-26021" is 17 characters of number plus spacing and
            // is perfectly valid input. The 20-char DB column holds the
            // NORMALIZED result, which PhoneNumber caps at 16 (+ and 15
            // digits). Validating raw input against the storage width is what
            // would reject a legitimately-spaced number before it ever reached
            // the normalizer.
            'rows.*.phone_e164' => ['nullable', 'string', 'max:32'],
            // Loose shape-only validation here too — strict email format
            // checking, lowercasing, and the identity-conflict logic all
            // happen in ContactService::normalizeEmailInput() /
            // resolveContactIdentity(), shared with the CSV path, so both
            // import surfaces stay in sync on exactly what counts as a valid,
            // usable email.
            'rows.*.email' => ['nullable', 'string', 'max:191'],
            'rows.*.tag_id' => [
                'nullable',
                'integer',
                Rule::exists('contact_tags', 'id')->where('workspace_id', $workspaceId),
            ],
            'rows.*.segment_id' => [
                'nullable',
                'integer',
                Rule::exists('segments', 'id')->where(fn ($q) => $q->where('workspace_id', $workspaceId)->where('type', 'static')),
            ],
            // Loose shape-only validation here — canonical gender values and
            // strict YYYY-MM-DD date checking happen in
            // ContactService::importGridRows() (via normalizeProfileRow(),
            // shared with the CSV import path) so both import surfaces stay
            // in sync on exactly what "valid" means for these six fields.
            'rows.*.gender' => ['nullable', 'string', 'max:32'],
            'rows.*.birthday' => ['nullable', 'string', 'max:10'],
            'rows.*.anniversary_date' => ['nullable', 'string', 'max:10'],
            'rows.*.city' => ['nullable', 'string', 'max:128'],
            'rows.*.state' => ['nullable', 'string', 'max:128'],
            'rows.*.postal_code' => ['nullable', 'string', 'max:20'],
            // Same loose-shape-here / canonical-elsewhere split as gender —
            // ContactService::resolveOptInFields() (shared with the CSV path)
            // is what actually accepts only Yes/No/True/False/1/0 and rejects
            // the row on anything else.
            // max:32, not a tight fit to "Yes"/"No" — an invalid free-text
            // value (e.g. "subscribed") must reach ContactService for a
            // proper ROW-SPECIFIC rejection with the row number and the
            // field name in it. A tight max here would instead reject the
            // whole REQUEST with a generic Laravel validation error that
            // names no row at all — exactly the kind of non-row-specific
            // error requirement 3 forbids.
            'rows.*.opt_in_whatsapp' => ['nullable', 'string', 'max:32'],
            'rows.*.opt_in_sms' => ['nullable', 'string', 'max:32'],
            'rows.*.opt_in_email' => ['nullable', 'string', 'max:32'],
        ]);

        // Tag each row with its ORIGINAL grid position before filtering out
        // blank spare rows — the grid always renders extra empty rows
        // (minSpareRows/DEFAULT_ROWS), so without this, a validation error
        // on the 5th filled-in row would be reported as "Row 2" (its
        // position among only the non-blank rows) instead of the row number
        // the admin actually sees on screen.
        $numberedRows = [];
        foreach ($validated['rows'] as $index => $row) {
            $numberedRows[] = $row + ['_row_number' => $index + 1];
        }

        // ⚠️ Phone OR email, not phone alone. This filter exists to drop the
        // grid's own blank spare rows (minSpareRows/DEFAULT_ROWS) before they
        // ever reach the service — it is NOT the identity-required check
        // (ContactService::bulkImport()/importGridRows() still reject, by
        // name, any surviving row that has neither). A phone-only condition
        // here would silently discard every genuine email-only row before it
        // had a chance to be validated at all — no row-specific error, just
        // one fewer contact than the admin expected.
        $rows = array_values(array_filter(
            $numberedRows,
            fn (array $r) => (isset($r['phone_e164']) && trim((string) $r['phone_e164']) !== '')
                || (isset($r['email']) && trim((string) $r['email']) !== '')
        ));

        if ($rows === []) {
            throw ValidationException::withMessages([
                'rows' => 'Add at least one row with a phone number (full international form, e.g. +918630026021, or local digits with a default phone country selected) or a valid email address.',
            ]);
        }

        $stats = $this->contactService->importGridRows(
            $workspaceId,
            $rows,
            'import',
            $validated['default_country'] ?? null
        );

        $request->session()->flash(
            'success',
            "Bulk import finished: {$stats['created']} created, {$stats['updated']} updated, {$stats['skipped']} skipped."
        );

        if (! empty($stats['errors'])) {
            $request->session()->flash('import_errors', array_slice($stats['errors'], 0, 20));
        }

        return Inertia::render('Contacts/BulkImport', $this->bulkImportProps($request));
    }

    public function bulkDestroy(Request $request): RedirectResponse
    {
        $workspaceId = WorkspaceContext::id() ?? $request->user()->workspace_id;
        $validated = $request->validate([
            'uuids' => ['required', 'array', 'max:500'],
            'uuids.*' => ['string', 'uuid'],
        ]);

        $deleted = Contact::where('workspace_id', $workspaceId)
            ->whereIn('uuid', $validated['uuids'])
            ->delete();

        return back()->with('success', "{$deleted} contact(s) deleted.");
    }

    public function export(Request $request): HttpResponse
    {
        $workspaceId = WorkspaceContext::id() ?? $request->user()->workspace_id;

        $contacts = Contact::where('workspace_id', $workspaceId)
            ->with('tags')
            ->when($request->uuids, fn ($q) => $q->whereIn('uuid', explode(',', $request->uuids)))
            ->when($request->search, fn ($q) => $q->where(function ($q) use ($request) {
                $q->where('first_name', 'like', '%'.$request->search.'%')
                    ->orWhere('last_name', 'like', '%'.$request->search.'%')
                    ->orWhere('phone_e164', 'like', '%'.$request->search.'%')
                    ->orWhere('email', 'like', '%'.$request->search.'%');
            }))
            ->latest()
            ->get();

        // Appended after the existing columns, not inserted between them —
        // anything already parsing this CSV positionally keeps working
        // unchanged; only new trailing columns are added.
        //
        // ⚠️ "WhatsApp Opt-in" / "SMS Opt-in" / "Email Opt-in" — renamed from
        // "Opt-in WhatsApp" etc. to match the canonical header this same
        // change teaches on the import side (IMPORT_HEADER_MAP,
        // downloadSampleCsv()/downloadSampleWorkbook()), so a freshly
        // exported file re-imports using the exact words the sample already
        // taught. The OLD casing is still recognized on import as a
        // backward-compatible alias — see IMPORT_HEADER_MAP.
        $headers = [
            'First Name', 'Last Name', 'Phone', 'Email', 'Tags', 'WhatsApp Opt-in', 'SMS Opt-in', 'Email Opt-in', 'Created At',
            'Gender', 'Birthday', 'Anniversary Date', 'City', 'State', 'Postal Code / PIN Code',
        ];
        $rows = $contacts->map(fn ($c) => [
            Demo::name($c->first_name) ?? '',
            Demo::name($c->last_name) ?? '',
            Demo::phone($c->phone_e164) ?? '',
            Demo::email($c->email) ?? '',
            $c->tags->pluck('name')->join(', '),
            $c->opt_in_whatsapp ? 'yes' : 'no',
            $c->opt_in_sms ? 'yes' : 'no',
            $c->opt_in_email ? 'yes' : 'no',
            $c->created_at?->toDateTimeString() ?? '',
            // ⚠️ Demo::maskValue(..., 'redact') only masks is_scalar() values
            // (see Demo::maskValue) — $c->birthday/$c->anniversary_date are
            // Carbon instances (date cast), not scalars, so they MUST be
            // converted to their ISO string first or masking would silently
            // no-op and leak the real date in demo mode.
            $c->gender ? (Demo::active() ? Demo::maskValue(Contact::GENDER_LABELS[$c->gender] ?? $c->gender, 'redact') : (Contact::GENDER_LABELS[$c->gender] ?? $c->gender)) : '',
            $c->birthday ? (Demo::active() ? Demo::maskValue($c->birthday->toDateString(), 'redact') : $c->birthday->toDateString()) : '',
            $c->anniversary_date ? (Demo::active() ? Demo::maskValue($c->anniversary_date->toDateString(), 'redact') : $c->anniversary_date->toDateString()) : '',
            $c->city ? (Demo::active() ? Demo::maskValue($c->city, 'redact') : $c->city) : '',
            $c->state ? (Demo::active() ? Demo::maskValue($c->state, 'redact') : $c->state) : '',
            $c->postal_code ? (Demo::active() ? Demo::maskValue($c->postal_code, 'redact') : $c->postal_code) : '',
        ]);

        $csv = collect([$headers])->merge($rows)->map(fn ($row) => collect($row)->map(fn ($v) => '"'.str_replace('"', '""', $v).'"')->join(',')
        )->join("\n");

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="contacts-'.now()->format('Y-m-d').'.csv"',
        ]);
    }

    private function authoriseContact(Request $request, Contact $contact): void
    {
        $workspaceId = WorkspaceContext::id() ?? $request->user()->workspace_id;
        abort_unless((int) $contact->workspace_id === (int) $workspaceId, 403);
    }

    /** @return array<string, array<int, mixed>> */
    private function profileValidationRules(): array
    {
        return [
            'gender' => ['nullable', 'string', 'max:32', Rule::in(Contact::GENDERS)],
            'birthday' => ['nullable', 'date', 'after_or_equal:1900-01-01', 'before_or_equal:today'],
            'anniversary_date' => ['nullable', 'date', 'before_or_equal:today'],
            'city' => ['nullable', 'string', 'max:128'],
            'state' => ['nullable', 'string', 'max:128'],
            'postal_code' => ['nullable', 'string', 'max:20'],
        ];
    }
}
