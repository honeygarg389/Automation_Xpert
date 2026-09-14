<?php

namespace Tests\Feature;

use App\Events\ContactCreated;
use App\Jobs\DispatchWebhookJob;
use App\Models\WebhookEndpoint;
use App\Modules\Automation\Jobs\ExecuteAutomationRunJob;
use App\Modules\Automation\Models\Automation;
use App\Modules\Automation\Models\AutomationRun;
use App\Modules\Shared\Models\Contact;
use App\Support\PhoneNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Bug report (real import, real sheet): every row was skipped because the
 * Phone column held local Indian numbers like `8630026021` instead of
 * `+918630026021`. The screen said only:
 *
 *     0 created, 0 updated, 7 skipped
 *
 * with no reason shown, and because the whole row was dropped the Name column
 * looked like it had been ignored too.
 *
 * ─── What was actually wrong, in two different places ───────────────────────
 *
 * The two import paths did NOT share phone handling, and each was broken in
 * its own direction:
 *
 *   XLSX grid  `importGridRows()` tested `str_starts_with($phone, '+')` and on
 *              failure did `$stats['skipped']++; continue;` — with no entry in
 *              `$stats['errors']`. That is the silent skip the user saw.
 *
 *   CSV        `bulkImport()` validated the phone NOT AT ALL. A local number
 *              went straight through `upsert()` into `phone_e164`, so the CSV
 *              path was quietly storing non-canonical values in the column
 *              that IS the contact's identity — a worse bug than the visible
 *              one, and invisible until someone tried to message the contact.
 *
 * Both now go through {@see PhoneNumber::normalizeForImport()}.
 *
 * ─── The invariant these tests defend ───────────────────────────────────────
 *
 * `contacts` is uniquely keyed `(workspace_id, phone_e164)`. Accepting a
 * friendlier INPUT format must never relax the STORED format, or the same
 * person imported twice in two spellings becomes two contacts. Every
 * assertion below reads the stored row back rather than trusting the request
 * — mass assignment fails silently, so the payload and the persisted value
 * are two different claims.
 */
class ContactImportPhoneNormalizationTest extends TestCase
{
    use RefreshDatabase;

    /** Honey's actual number from the bug report. */
    private const LOCAL_IN = '8630026021';

    private const LOCAL_IN_E164 = '+918630026021';

    private function csvUpload(array $headers, array $rows): UploadedFile
    {
        $stream = fopen('php://temp', 'w+');
        fputcsv($stream, $headers);
        foreach ($rows as $row) {
            fputcsv($stream, $row);
        }
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        return UploadedFile::fake()->createWithContent('contacts.csv', $content);
    }

    /** @param array<string, mixed> $overrides */
    private function gridRow(string $phone, array $overrides = []): array
    {
        return array_merge([
            'name' => null,
            'phone_e164' => $phone,
            'tag_id' => null,
            'segment_id' => null,
            'gender' => null,
            'birthday' => null,
            'anniversary_date' => null,
            'city' => null,
            'state' => null,
            'postal_code' => null,
        ], $overrides);
    }

    // ══ 1. Local number + selected India → stored as E.164 ═════════════

    #[Test]
    public function csv_import_normalizes_a_local_indian_number_with_india_selected(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        $this->actingAs($user)->post(route('client.contacts.import'), [
            'file' => $this->csvUpload(['First Name', 'Phone'], [['Honey', self::LOCAL_IN]]),
            'default_country' => 'IN',
        ])->assertRedirect();

        $contact = Contact::where('workspace_id', $workspace->id)->sole();
        $this->assertSame(self::LOCAL_IN_E164, $contact->phone_e164);
        // The original report's second symptom: Name was lost, because the
        // whole row was dropped before it reached upsert().
        $this->assertSame('Honey', $contact->first_name);
    }

    #[Test]
    public function grid_import_normalizes_a_local_indian_number_with_india_selected(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        $this->actingAs($user)->post(route('client.contacts.bulk-store'), [
            'default_country' => 'IN',
            'rows' => [$this->gridRow(self::LOCAL_IN, ['name' => 'Honey Garg'])],
        ])->assertOk();

        $contact = Contact::where('workspace_id', $workspace->id)->sole();
        $this->assertSame(self::LOCAL_IN_E164, $contact->phone_e164);
        $this->assertSame('Honey', $contact->first_name);
        $this->assertSame('Garg', $contact->last_name);
    }

    #[Test]
    public function a_local_number_written_with_a_trunk_zero_or_spacing_normalizes_identically(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        // All three spellings are the same subscriber. If any of them stored a
        // different string the unique key would let the same person in twice.
        $this->actingAs($user)->post(route('client.contacts.bulk-store'), [
            'default_country' => 'IN',
            'rows' => [
                $this->gridRow('08630026021'),
                $this->gridRow('86300 26021'),
                $this->gridRow('86300-26021'),
            ],
        ])->assertOk();

        $this->assertSame(1, Contact::where('workspace_id', $workspace->id)->count());
        $this->assertSame(
            self::LOCAL_IN_E164,
            Contact::where('workspace_id', $workspace->id)->sole()->phone_e164
        );
    }

    /**
     * ⚠️ A NON-INDIA COUNTRY, PROVING THE SELECTOR IS NOT SECRETLY INDIA-ONLY.
     * The country-list expansion this branch adds (55 -> 232 entries,
     * covering every ISO 3166-1 territory with a real E.164 calling code,
     * including clusters that share one calling code) is meaningless if
     * every test only ever exercises IN — this is the one that would fail if
     * anything about the fix were quietly hard-coded to India.
     */
    #[Test]
    public function a_local_number_with_a_non_india_country_selected_normalizes_correctly(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        // Germany: chosen because its national length in the table is a WIDE
        // range (6-13), the opposite shape from India's fixed 10 — a test
        // that only ever uses a fixed-length country could hide a bug in the
        // range-based branch of normalizeLocal().
        $this->actingAs($user)->post(route('client.contacts.bulk-store'), [
            'default_country' => 'DE',
            'rows' => [$this->gridRow('15123456789', ['name' => 'Klaus Müller'])],
        ])->assertOk();

        $contact = Contact::where('workspace_id', $workspace->id)->sole();
        $this->assertSame('+4915123456789', $contact->phone_e164);
        $this->assertSame('Klaus', $contact->first_name);
    }

    #[Test]
    public function a_local_number_in_a_country_sharing_a_calling_code_resolves_to_that_countrys_own_row(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        // Jersey shares calling code +44 with the United Kingdom. Selecting
        // Jersey specifically (not GB) must still produce +44 — proving the
        // shared-calling-code entries are real, independently selectable
        // rows, not a single collapsed "+44 country".
        $this->actingAs($user)->post(route('client.contacts.bulk-store'), [
            'default_country' => 'JE',
            'rows' => [$this->gridRow('7797123456')],
        ])->assertOk();

        $this->assertSame(
            '+447797123456',
            Contact::where('workspace_id', $workspace->id)->sole()->phone_e164
        );
    }

    // ══ 2. Explicit E.164 from another country is untouched ════════════

    #[Test]
    public function csv_import_keeps_an_explicit_foreign_e164_number_unchanged(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        $this->actingAs($user)->post(route('client.contacts.import'), [
            'file' => $this->csvUpload(['Phone'], [['+15551234567']]),
            'default_country' => 'IN',
        ])->assertRedirect();

        // India is selected, but a +1 number must NOT be re-interpreted.
        $this->assertSame(
            '+15551234567',
            Contact::where('workspace_id', $workspace->id)->sole()->phone_e164
        );
    }

    #[Test]
    public function grid_import_keeps_an_explicit_foreign_e164_number_unchanged(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        $this->actingAs($user)->post(route('client.contacts.bulk-store'), [
            'default_country' => 'IN',
            'rows' => [$this->gridRow('+44 7700 900123')],
        ])->assertOk();

        $this->assertSame(
            '+447700900123',
            Contact::where('workspace_id', $workspace->id)->sole()->phone_e164
        );
    }

    /**
     * "Strictly validated and normalized as E.164: `+` followed by digits
     * only, maximum 15 digits, no malformed values" — each malformed shape
     * named in that requirement gets its own row, on both import paths, and
     * each must reject the WHOLE row rather than storing a mangled value.
     *
     * @return array<string, array{0: string}>
     */
    public static function malformedE164Provider(): array
    {
        return [
            'letters after +' => ['+1abc5551234'],
            'leading zero after +' => ['+0123456789'],
            'bare plus, no digits' => ['+'],
            'sixteen digits — exceeds the 15-digit E.164 ceiling' => ['+1234567890123456'],
            'plus with only punctuation' => ['+--()'],
        ];
    }

    #[Test]
    #[DataProvider('malformedE164Provider')]
    public function csv_import_rejects_malformed_explicit_e164_values(string $malformed): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        $this->actingAs($user)->post(route('client.contacts.import'), [
            'file' => $this->csvUpload(['Phone'], [[$malformed]]),
            'default_country' => 'IN',
        ])->assertRedirect();

        $this->assertSame(0, Contact::where('workspace_id', $workspace->id)->count());
        $this->assertNotEmpty(session('import_errors'), "Malformed value '{$malformed}' must produce a visible reason, not a silent skip.");
    }

    #[Test]
    #[DataProvider('malformedE164Provider')]
    public function grid_import_rejects_malformed_explicit_e164_values(string $malformed): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        $this->actingAs($user)->post(route('client.contacts.bulk-store'), [
            'default_country' => 'IN',
            'rows' => [$this->gridRow($malformed)],
        ])->assertOk();

        $this->assertSame(0, Contact::where('workspace_id', $workspace->id)->count());
        $this->assertNotEmpty(session('import_errors'), "Malformed value '{$malformed}' must produce a visible reason, not a silent skip.");
    }

    // ══ 3. Local number with NO default country → row-specific error ═══

    #[Test]
    public function csv_import_rejects_a_local_number_with_no_default_country_and_says_why(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        $this->actingAs($user)->post(route('client.contacts.import'), [
            'file' => $this->csvUpload(['First Name', 'Phone'], [['Honey', self::LOCAL_IN]]),
        ])->assertRedirect();

        $this->assertSame(0, Contact::where('workspace_id', $workspace->id)->count());

        $errors = session('import_errors');
        $this->assertNotEmpty($errors, 'A skipped row must explain itself, not just increment a counter.');
        $joined = implode(' ', $errors);
        // Row 2, not Row 1: line 1 of the CSV is the header, so this is the
        // row number the admin sees when they open the file.
        $this->assertStringContainsString('Row 2', $joined);
        $this->assertStringContainsString('no default phone country is selected', $joined);
    }

    #[Test]
    public function grid_import_rejects_a_local_number_with_no_default_country_and_says_why(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        $this->actingAs($user)->post(route('client.contacts.bulk-store'), [
            'rows' => [$this->gridRow(self::LOCAL_IN)],
        ])->assertOk();

        $this->assertSame(0, Contact::where('workspace_id', $workspace->id)->count());

        $errors = session('import_errors');
        $this->assertNotEmpty($errors, 'THE ORIGINAL BUG: this used to be a silent $stats[skipped]++ with no message.');
        $this->assertStringContainsString('Row 1', implode(' ', $errors));
        $this->assertStringContainsString('no default phone country is selected', implode(' ', $errors));
    }

    // ══ 4. Invalid local number → rejected, nothing partially written ══

    #[Test]
    public function csv_import_rejects_an_invalid_local_number_without_creating_a_partial_contact(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        $this->actingAs($user)->post(route('client.contacts.import'), [
            'file' => $this->csvUpload(
                ['First Name', 'Last Name', 'Phone', 'City'],
                [['Partial', 'Row', '12345', 'Bengaluru']]
            ),
            'default_country' => 'IN',
        ])->assertRedirect();

        // Not "no contact with that phone" — NO contact at all. A row rejected
        // on its phone must not leave a name/city-only record behind.
        $this->assertSame(0, Contact::where('workspace_id', $workspace->id)->count());
        $this->assertStringContainsString(
            'invalid for the selected default country',
            implode(' ', session('import_errors') ?? [])
        );
    }

    #[Test]
    public function grid_import_rejects_an_invalid_local_number_without_creating_a_partial_contact(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        $this->actingAs($user)->post(route('client.contacts.bulk-store'), [
            'default_country' => 'IN',
            'rows' => [$this->gridRow('12345', ['name' => 'Partial Row', 'city' => 'Bengaluru'])],
        ])->assertOk();

        $this->assertSame(0, Contact::where('workspace_id', $workspace->id)->count());
        $this->assertStringContainsString(
            'invalid for the selected default country',
            implode(' ', session('import_errors') ?? [])
        );
    }

    #[Test]
    public function a_non_numeric_phone_is_rejected_with_its_own_reason(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        $this->actingAs($user)->post(route('client.contacts.bulk-store'), [
            'default_country' => 'IN',
            'rows' => [$this->gridRow('not-a-number')],
        ])->assertOk();

        $this->assertSame(0, Contact::where('workspace_id', $workspace->id)->count());
        $this->assertStringContainsString('not digits', implode(' ', session('import_errors') ?? []));
    }

    // ══ 5. Repeated phone → ONE contact, never duplicates ══════════════

    #[Test]
    public function csv_import_merges_repeated_rows_for_the_same_number_into_one_contact(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        // Honey's sheet had the same number on several rows, written
        // inconsistently. All four spellings are one person.
        $this->actingAs($user)->post(route('client.contacts.import'), [
            'file' => $this->csvUpload(['First Name', 'City', 'Phone'], [
                ['Honey', 'Delhi', self::LOCAL_IN],
                ['Honey', '', '08630026021'],
                ['Honey', 'Gurugram', '+918630026021'],
                ['Honey', '', '86300 26021'],
            ]),
            'default_country' => 'IN',
        ])->assertRedirect();

        $this->assertSame(1, Contact::where('workspace_id', $workspace->id)->count());

        $contact = Contact::where('workspace_id', $workspace->id)->sole();
        $this->assertSame(self::LOCAL_IN_E164, $contact->phone_e164);
        // Last non-blank write wins, which is the pre-existing upsert
        // behaviour — the point here is that it MERGED rather than duplicated.
        $this->assertSame('Gurugram', $contact->city);
    }

    #[Test]
    public function grid_import_merges_repeated_rows_for_the_same_number_into_one_contact(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        $this->actingAs($user)->post(route('client.contacts.bulk-store'), [
            'default_country' => 'IN',
            'rows' => [
                $this->gridRow(self::LOCAL_IN, ['name' => 'Honey Garg']),
                $this->gridRow('+918630026021', ['city' => 'Gurugram']),
                $this->gridRow('08630026021', ['state' => 'Haryana']),
            ],
        ])->assertOk();

        $this->assertSame(1, Contact::where('workspace_id', $workspace->id)->count());

        $contact = Contact::where('workspace_id', $workspace->id)->sole();
        $this->assertSame(self::LOCAL_IN_E164, $contact->phone_e164);
        $this->assertSame('Gurugram', $contact->city);
        $this->assertSame('Haryana', $contact->state);
    }

    // ══ 6. Existing contact updates when the normalized phone matches ══

    #[Test]
    public function a_local_number_updates_the_existing_contact_stored_in_e164(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        $existing = Contact::create([
            'workspace_id' => $workspace->id,
            'phone_e164' => self::LOCAL_IN_E164,
            'first_name' => 'Honey',
            'city' => 'Delhi',
        ]);

        $this->actingAs($user)->post(route('client.contacts.import'), [
            'file' => $this->csvUpload(['Phone', 'City'], [[self::LOCAL_IN, 'Gurugram']]),
            'default_country' => 'IN',
        ])->assertRedirect();

        $this->assertSame(1, Contact::where('workspace_id', $workspace->id)->count());
        $existing->refresh();
        $this->assertSame($existing->id, Contact::where('workspace_id', $workspace->id)->sole()->id);
        $this->assertSame('Gurugram', $existing->city);
        $this->assertSame('Honey', $existing->first_name, 'An omitted column must not blank an existing value.');
    }

    #[Test]
    public function the_grid_path_also_updates_rather_than_duplicating_an_existing_contact(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        $existing = Contact::create([
            'workspace_id' => $workspace->id,
            'phone_e164' => self::LOCAL_IN_E164,
            'first_name' => 'Honey',
        ]);

        $this->actingAs($user)->post(route('client.contacts.bulk-store'), [
            'default_country' => 'IN',
            'rows' => [$this->gridRow(self::LOCAL_IN, ['city' => 'Noida'])],
        ])->assertOk();

        $this->assertSame(1, Contact::where('workspace_id', $workspace->id)->count());
        $this->assertSame('Noida', $existing->fresh()->city);
    }

    // ══ 7. Personal Details still import alongside the new phone rules ═

    #[Test]
    public function personal_details_still_import_when_the_phone_is_a_local_number(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        $this->actingAs($user)->post(route('client.contacts.import'), [
            'file' => $this->csvUpload(
                ['First Name', 'Phone', 'Gender', 'Birthday', 'Anniversary Date', 'City', 'State', 'Postal Code / PIN Code'],
                [['Honey', self::LOCAL_IN, 'Female', '1992-04-16', '2018-11-03', 'Mumbai', 'Maharashtra', '400001']]
            ),
            'default_country' => 'IN',
        ])->assertRedirect();

        $contact = Contact::where('workspace_id', $workspace->id)->sole();
        $this->assertSame(self::LOCAL_IN_E164, $contact->phone_e164);
        $this->assertSame('female', $contact->gender);
        $this->assertSame('1992-04-16', $contact->birthday?->toDateString());
        $this->assertSame('2018-11-03', $contact->anniversary_date?->toDateString());
        $this->assertSame('Mumbai', $contact->city);
        $this->assertSame('Maharashtra', $contact->state);
        $this->assertSame('400001', $contact->postal_code);
    }

    #[Test]
    public function grid_personal_details_still_import_when_the_phone_is_a_local_number(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        $this->actingAs($user)->post(route('client.contacts.bulk-store'), [
            'default_country' => 'IN',
            'rows' => [$this->gridRow(self::LOCAL_IN, [
                'name' => 'Asha Rao',
                'gender' => 'Female',
                'birthday' => '1992-04-16',
                'city' => 'Mumbai',
                'postal_code' => '400001',
            ])],
        ])->assertOk();

        $contact = Contact::where('workspace_id', $workspace->id)->sole();
        $this->assertSame(self::LOCAL_IN_E164, $contact->phone_e164);
        $this->assertSame('female', $contact->gender);
        $this->assertSame('1992-04-16', $contact->birthday?->toDateString());
        $this->assertSame('Mumbai', $contact->city);
        $this->assertSame('400001', $contact->postal_code);
    }

    // ══ 8. Workspace isolation ═════════════════════════════════════════

    #[Test]
    public function a_normalized_number_does_not_cross_into_another_workspace(): void
    {
        ['user' => $userA, 'workspace' => $workspaceA] = $this->createWorkspaceContext();
        ['workspace' => $workspaceB] = $this->createWorkspaceContext();

        // Workspace B already owns this number, stored in E.164.
        $bContact = Contact::create([
            'workspace_id' => $workspaceB->id,
            'phone_e164' => self::LOCAL_IN_E164,
            'first_name' => 'Belongs To B',
        ]);

        $this->actingAs($userA)->post(route('client.contacts.bulk-store'), [
            'default_country' => 'IN',
            'rows' => [$this->gridRow(self::LOCAL_IN, ['name' => 'Imported By A'])],
        ])->assertOk();

        // A NEW contact in A — the normalization must not make A's import
        // reach across and update B's row.
        $this->assertSame(1, Contact::where('workspace_id', $workspaceA->id)->count());
        $this->assertSame(
            self::LOCAL_IN_E164,
            Contact::where('workspace_id', $workspaceA->id)->sole()->phone_e164
        );
        $this->assertSame('Belongs To B', $bContact->fresh()->first_name);
    }

    // ══ 9. No outbound messaging / automation is triggered by an import ═

    /**
     * ⚠️ THE FIX. `ContactService::bulkImport()` and `importGridRows()` now
     * both pass `dispatchCreatedEvent: false` to `upsert()`, matching the
     * pre-existing convention `SyncStoreCustomersJob`/`BackfillStoreOrdersJob`
     * already used for e-commerce bulk sync. `ContactCreated` — the ONLY
     * event either import path could reach — never dispatches for an
     * imported row, so NOTHING downstream of it can fire: no automation run,
     * no outbound webhook, no dashboard broadcast, no queued job of any kind.
     *
     * Confirmed via Event::fake(): the event itself is never dispatched, which
     * is the strongest possible assertion — it does not depend on how many
     * listeners are wired to it, so it is immune to the double-registration
     * defect documented below.
     */
    #[Test]
    public function importing_dispatches_no_outbound_messaging_work(): void
    {
        Event::fake([ContactCreated::class]);
        Queue::fake();
        Bus::fake();

        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        $this->actingAs($user)->post(route('client.contacts.import'), [
            'file' => $this->csvUpload(['Phone'], [[self::LOCAL_IN]]),
            'default_country' => 'IN',
        ])->assertRedirect();

        $this->actingAs($user)->post(route('client.contacts.bulk-store'), [
            'default_country' => 'IN',
            'rows' => [$this->gridRow('9810000002')],
        ])->assertOk();

        $this->assertSame(2, Contact::where('workspace_id', $workspace->id)->count());

        // The event that drives EVERY downstream effect (automations, the
        // outbound webhook fan-out, the dashboard broadcast) never fires at
        // all for an imported row.
        Event::assertNotDispatched(ContactCreated::class);

        // And, redundantly but explicitly by name — nothing that could
        // message the contact or fire an automation was queued.
        Queue::assertNothingPushed();
        Bus::assertNothingDispatched();
    }

    /**
     * The behavioural guarantee stated in product terms: a workspace with a
     * LIVE `contact.created` automation (the exact "welcome message" shape
     * named in the bug report) gets ZERO automation runs from either import
     * path, for either a newly-created or an updated-existing contact.
     *
     * ⚠️ This is the inverse of what this same test used to assert. It
     * previously PINNED automations firing as accepted, current behaviour,
     * with a note that fixing it would "invert" the test. This is that
     * inversion.
     */
    #[Test]
    public function an_active_contact_created_automation_does_not_fire_for_either_import_path(): void
    {
        Queue::fake();

        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        Automation::create([
            'workspace_id' => $workspace->id,
            'name' => 'Welcome new contacts',
            'status' => 'active',
            'trigger_type' => 'contact.created',
            'nodes' => [],
            'edges' => [],
        ]);

        $this->actingAs($user)->post(route('client.contacts.import'), [
            'file' => $this->csvUpload(['Phone'], [[self::LOCAL_IN]]),
            'default_country' => 'IN',
        ])->assertRedirect();

        $this->actingAs($user)->post(route('client.contacts.bulk-store'), [
            'default_country' => 'IN',
            'rows' => [
                $this->gridRow('9810000003'),
                $this->gridRow('9810000004'),
            ],
        ])->assertOk();

        // Three NEW contacts across both import paths — if suppression were
        // not wired to BOTH paths, this would catch whichever one was missed.
        $this->assertSame(3, Contact::where('workspace_id', $workspace->id)->count());

        Queue::assertNotPushed(ExecuteAutomationRunJob::class);
        $this->assertSame(0, AutomationRun::count(), 'No AutomationRun row may exist — not even one that never got queued.');
    }

    /**
     * The other listener `ContactCreated` drives: outbound webhooks. A
     * workspace with a webhook endpoint subscribed to `contact.created` must
     * receive nothing when contacts are imported — this is a materially
     * different customer-facing effect from an in-app automation and needs
     * its own proof, not an inference from the automation test above.
     */
    #[Test]
    public function a_subscribed_outbound_webhook_does_not_fire_for_either_import_path(): void
    {
        Queue::fake();

        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        WebhookEndpoint::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'url' => 'https://example.test/webhooks/contact-created',
            'secret' => WebhookEndpoint::generateSecret(),
            'events' => ['contact.created'],
            'enabled' => true,
        ]);

        $this->actingAs($user)->post(route('client.contacts.import'), [
            'file' => $this->csvUpload(['Phone'], [[self::LOCAL_IN]]),
            'default_country' => 'IN',
        ])->assertRedirect();

        $this->actingAs($user)->post(route('client.contacts.bulk-store'), [
            'default_country' => 'IN',
            'rows' => [$this->gridRow('9810000005')],
        ])->assertOk();

        $this->assertSame(2, Contact::where('workspace_id', $workspace->id)->count());

        Queue::assertNotPushed(DispatchWebhookJob::class);
    }

    /**
     * ⚠️ THE DOUBLE-LISTENER-REGISTRATION FINDING — investigated, confirmed,
     * and deliberately NOT fixed in this branch. Full detail in the PR
     * report; summarised here because this is the test that proves it does
     * not touch the import path.
     *
     * ROOT CAUSE (confirmed, not inferred): `Illuminate\Foundation\
     * Application::configure()` calls `->withEvents()` — Laravel's event
     * auto-discovery, `discover: true` by default — UNCONDITIONALLY, before
     * `bootstrap/app.php`'s own `->withRouting()->withMiddleware()
     * ->withExceptions()` chain runs. `bootstrap/app.php` never calls
     * `->withEvents(discover: false)` to turn it back off. So Laravel scans
     * `app/Listeners` and auto-registers every public `handle*`-prefixed
     * method whose single parameter is a dispatchable event — THE SAME
     * listener methods `AppServiceProvider::boot()` ALSO registers explicitly
     * via `Event::listen(...)`. Every one of them fires twice.
     *
     * EXACT REPRODUCTION:
     *
     *     php artisan tinker --execute='
     *         $r = new ReflectionClass(app("events"));
     *         $p = $r->getProperty("listeners"); $p->setAccessible(true);
     *         var_export($p->getValue(app("events"))[App\Events\ContactCreated::class]);
     *     '
     *
     *     => 4 entries: [AutomationTriggerListener,"handleContactCreated"],
     *        [DispatchOutboundWebhookListener,"handleContactCreated"] (both
     *        discovery's array form) PLUS
     *        "App\Listeners\AutomationTriggerListener@handleContactCreated",
     *        "App\Listeners\DispatchOutboundWebhookListener@handleContactCreated"
     *        (both AppServiceProvider's string form).
     *
     *     Confirmed as the mechanism, not a coincidence, via:
     *         Illuminate\Foundation\Events\DiscoverEvents::within(app_path('Listeners'), base_path())
     *     which independently returns the exact same
     *     ContactCreated => [AutomationTriggerListener@handleContactCreated,
     *                         DispatchOutboundWebhookListener@handleContactCreated]
     *     pair discovery would add on top of the explicit registration.
     *
     * AFFECTED LISTENERS: every explicitly-registered `Event::listen(...)`
     * call in `AppServiceProvider::boot()` whose listener class has a
     * `handle*`-prefixed public method — confirmed for `ContactCreated`
     * (both listeners) and consistent with the elevated listener count
     * measured on `MessageReceived` (9, where 5 are explicit). A full sweep
     * checked every listener class has SOME explicit reference in
     * `AppServiceProvider.php` (none rely on discovery alone), so disabling
     * discovery would not silently remove a listener with no fallback.
     *
     * IMPACT: every EXISTING (non-import) contact-creation path that still
     * dispatches `ContactCreated` — `ContactController::store()`, WhatsApp/
     * Instagram/Messenger inbound auto-creation, `ProcessEcommerceWebhookJob`,
     * `LaunchCampaignJob`'s recipient upload — fires its `contact.created`
     * automation TWICE and its outbound webhook TWICE per contact. A
     * configured "send a WhatsApp welcome message" automation sends it twice
     * to a real customer today, independent of anything in this branch.
     *
     * WHY NOT FIXED HERE: it is systemic (every `Event::listen()` call in the
     * app, not one event), it is unrelated to contact import, and per
     * CLAUDE.md ("one concern per branch") it needs its own branch with its
     * own full-suite run — `bootstrap/app.php` adding
     * `->withEvents(discover: false)` is the minimal fix, verified above as
     * safe against every current listener, but that verification and the
     * regression test for it belong to that dedicated fix, not here.
     *
     * WHAT THIS TEST PROVES INSTEAD: the import path is immune to the
     * duplication REGARDLESS of whether that separate bug is ever fixed,
     * because `Event::assertNotDispatched(ContactCreated::class)` in the test
     * above is true at the EVENT level — zero dispatches has no "twice" to
     * multiply. This test adds the automation-count half of that proof: even
     * with TWO automations that could each independently double-fire, still
     * zero runs.
     */
    #[Test]
    public function double_listener_registration_cannot_manifest_as_duplicate_automation_runs_during_import(): void
    {
        Queue::fake();

        // Confirm the defect is actually present in this run, so this test
        // cannot pass vacuously if someone fixes it out from under it without
        // updating this comment — if ContactCreated ever drops back to 2
        // listeners, this assertion (not the ones below it) is what should
        // start failing and prompt a re-read of this docblock.
        $listenerCount = count(app('events')->getListeners(ContactCreated::class));
        $this->assertGreaterThanOrEqual(
            4,
            $listenerCount,
            'Expected the known double-registration (>=4 listeners on ContactCreated). '
            .'If this now reads 2, the systemic bug may have been fixed — this test should '
            .'still pass (it asserts zero regardless), but re-read the docblock above.'
        );

        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        Automation::create([
            'workspace_id' => $workspace->id,
            'name' => 'Welcome new contacts',
            'status' => 'active',
            'trigger_type' => 'contact.created',
            'nodes' => [],
            'edges' => [],
        ]);

        $this->actingAs($user)->post(route('client.contacts.bulk-store'), [
            'default_country' => 'IN',
            'rows' => [$this->gridRow(self::LOCAL_IN)],
        ])->assertOk();

        // Not "at most one" — exactly zero. Suppressing dispatch at the
        // source means the listener-count multiplier is irrelevant: 0 x 4
        // is still 0.
        Queue::assertNotPushed(ExecuteAutomationRunJob::class);
        $this->assertSame(0, AutomationRun::count());
    }

    // ══ 10. The country data the UI renders its examples from ══════════

    #[Test]
    public function both_import_screens_receive_the_same_country_list_and_no_preselection(): void
    {
        ['user' => $user] = $this->createWorkspaceContext();

        foreach (['client.contacts.index', 'client.contacts.bulk-import'] as $routeName) {
            $this->actingAs($user)->get(route($routeName))
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    // Requirement 5: no workspace/client country field exists in
                    // this codebase, so nothing may be preselected. See
                    // ContactController::suggestedPhoneCountry().
                    ->where('defaultPhoneCountry', null)
                    ->has('phoneCountries', count(PhoneNumber::COUNTRIES))
                    ->has('phoneCountries.0', fn ($c) => $c
                        ->hasAll(['code', 'name', 'calling_code', 'label', 'example'])
                    )
                );
        }
    }

    #[Test]
    public function the_example_offered_for_a_country_is_one_that_country_actually_accepts(): void
    {
        // The on-page helper text and the sample workbook both render
        // exampleFor(). If an example were not itself importable the page
        // would be teaching a format the importer rejects — so the example is
        // fed back through the real normalizer here rather than eyeballed.
        foreach (array_keys(PhoneNumber::COUNTRIES) as $code) {
            $example = PhoneNumber::exampleFor($code);
            $result = PhoneNumber::normalizeForImport($example, $code);

            $this->assertNull($result['error'], "Example for {$code} is not accepted by its own country rule.");
            $this->assertSame(PhoneNumber::exampleE164For($code), $result['phone']);
            $this->assertStringStartsWith('+', (string) $result['phone']);
        }
    }

    #[Test]
    public function an_unknown_default_country_is_refused_by_validation_rather_than_ignored(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        // A silently-ignored bad country code would fall back to "no default"
        // and skip every local row — the original confusing failure again.
        $this->actingAs($user)->post(route('client.contacts.bulk-store'), [
            'default_country' => 'XX',
            'rows' => [$this->gridRow(self::LOCAL_IN)],
        ])->assertSessionHasErrors('default_country');

        $this->assertSame(0, Contact::where('workspace_id', $workspace->id)->count());
    }
}
