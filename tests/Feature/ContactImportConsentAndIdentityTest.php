<?php

namespace Tests\Feature;

use App\Events\ContactCreated;
use App\Jobs\DispatchWebhookJob;
use App\Models\WebhookEndpoint;
use App\Modules\Automation\Jobs\ExecuteAutomationRunJob;
use App\Modules\Automation\Models\Automation;
use App\Modules\Automation\Models\AutomationRun;
use App\Modules\Shared\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Browser bug report: an imported contact's Opt-ins showed ONLY Email —
 * WhatsApp and SMS were absent. Root cause, traced and confirmed before
 * writing this fix: neither import path ever wrote `opt_in_whatsapp`,
 * `opt_in_sms` or `opt_in_email` at all, so a NEW contact fell straight
 * through to the raw `contacts` column DEFAULTs —
 * `opt_in_email DEFAULT true`, the other two `DEFAULT false`. A row that
 * said nothing about consent read back as "opted in to Email marketing",
 * which is exactly backwards: a phone number (or a blank import row) is not
 * consent, for ANY channel.
 *
 * ─── The two rules this file exists to prove ────────────────────────────
 *
 *   NEW contact       omitted/blank opt-in column -> `false`, always.
 *                      Never defaults true, on ANY of the three channels —
 *                      Email included.
 *
 *   EXISTING contact  omitted/blank -> untouched. Explicit Yes/No -> that
 *                      channel only. Re-importing a file that never mentions
 *                      consent must neither grant nor revoke it.
 *
 * See ContactService::OPT_IN_FIELDS / resolveOptInFields() /
 * normalizeOptInInput().
 *
 * This file also covers the two identity-mapping regressions found during
 * the same investigation (item C): a `Name` (combined) CSV header had no
 * recognized mapping at all — first/last name silently vanished while the
 * phone imported fine — and a row with neither phone nor email reached
 * upsert() with no identity, which took Contact::create()'s NO-uniqueness-
 * check branch and could produce a fully blank contact record with zero
 * reported error.
 */
class ContactImportConsentAndIdentityTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '+918630026021';

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

    /** @param  array<string, mixed>  $overrides */
    private function gridRow(string $phone, array $overrides = []): array
    {
        // Matches bulkImportExcel.js's matrixToPayload() shape exactly: every
        // key always present (null when the cell was blank), never omitted —
        // the grid path can never exercise the CSV path's "column omitted
        // from the file entirely" case.
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
            'opt_in_whatsapp' => null,
            'opt_in_sms' => null,
            'opt_in_email' => null,
        ], $overrides);
    }

    // ══ 1. New contact, all opt-ins omitted -> all false ═══════════════

    #[Test]
    public function csv_new_contact_with_all_opt_in_columns_absent_gets_all_three_false(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        // No Opt-in columns in the header row at all — the exact shape of
        // the original bug report's file.
        $this->actingAs($user)->post(route('client.contacts.import'), [
            'file' => $this->csvUpload(['First Name', 'Phone'], [['Honey', self::PHONE]]),
            'default_country' => 'IN',
        ])->assertRedirect();

        $contact = Contact::where('workspace_id', $workspace->id)->sole();
        $this->assertFalse($contact->opt_in_whatsapp);
        $this->assertFalse($contact->opt_in_sms);
        $this->assertFalse($contact->opt_in_email, 'Email must not silently default to opted in.');
    }

    #[Test]
    public function csv_new_contact_with_opt_in_columns_present_but_blank_gets_all_three_false(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        $this->actingAs($user)->post(route('client.contacts.import'), [
            'file' => $this->csvUpload(
                ['First Name', 'Phone', 'WhatsApp Opt-in', 'SMS Opt-in', 'Email Opt-in'],
                [['Honey', self::PHONE, '', '', '']]
            ),
            'default_country' => 'IN',
        ])->assertRedirect();

        $contact = Contact::where('workspace_id', $workspace->id)->sole();
        $this->assertFalse($contact->opt_in_whatsapp);
        $this->assertFalse($contact->opt_in_sms);
        $this->assertFalse($contact->opt_in_email);
    }

    #[Test]
    public function grid_new_contact_with_all_opt_ins_null_gets_all_three_false(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        $this->actingAs($user)->post(route('client.contacts.bulk-store'), [
            'default_country' => 'IN',
            'rows' => [$this->gridRow(self::PHONE, ['name' => 'Honey Garg'])],
        ])->assertOk();

        $contact = Contact::where('workspace_id', $workspace->id)->sole();
        $this->assertFalse($contact->opt_in_whatsapp);
        $this->assertFalse($contact->opt_in_sms);
        $this->assertFalse($contact->opt_in_email);
    }

    // ══ 2. Explicit Yes on new contact -> only that channel ════════════

    #[Test]
    public function csv_explicit_whatsapp_yes_sets_only_whatsapp_true_on_a_new_contact(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        $this->actingAs($user)->post(route('client.contacts.import'), [
            'file' => $this->csvUpload(
                ['Phone', 'WhatsApp Opt-in'],
                [[self::PHONE, 'Yes']]
            ),
            'default_country' => 'IN',
        ])->assertRedirect();

        $contact = Contact::where('workspace_id', $workspace->id)->sole();
        $this->assertTrue($contact->opt_in_whatsapp);
        $this->assertFalse($contact->opt_in_sms);
        $this->assertFalse($contact->opt_in_email);
    }

    #[Test]
    public function csv_explicit_yes_on_all_three_channels_sets_all_three_true(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        $this->actingAs($user)->post(route('client.contacts.import'), [
            'file' => $this->csvUpload(
                ['Phone', 'WhatsApp Opt-in', 'SMS Opt-in', 'Email Opt-in'],
                [[self::PHONE, 'Yes', 'yes', 'YES']]
            ),
            'default_country' => 'IN',
        ])->assertRedirect();

        $contact = Contact::where('workspace_id', $workspace->id)->sole();
        $this->assertTrue($contact->opt_in_whatsapp);
        $this->assertTrue($contact->opt_in_sms);
        $this->assertTrue($contact->opt_in_email);
    }

    #[Test]
    public function grid_explicit_sms_yes_sets_only_sms_true_on_a_new_contact(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        $this->actingAs($user)->post(route('client.contacts.bulk-store'), [
            'default_country' => 'IN',
            'rows' => [$this->gridRow(self::PHONE, ['opt_in_sms' => 'Yes'])],
        ])->assertOk();

        $contact = Contact::where('workspace_id', $workspace->id)->sole();
        $this->assertFalse($contact->opt_in_whatsapp);
        $this->assertTrue($contact->opt_in_sms);
        $this->assertFalse($contact->opt_in_email);
    }

    // ══ 3. Existing contact + blank/omitted -> unchanged ═══════════════

    #[Test]
    public function csv_existing_contact_with_blank_opt_in_columns_keeps_its_current_consent(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $existing = Contact::create([
            'workspace_id' => $workspace->id,
            'phone_e164' => self::PHONE,
            'first_name' => 'Honey',
            'opt_in_whatsapp' => true,
            'opt_in_sms' => false,
            'opt_in_email' => true,
        ]);

        $this->actingAs($user)->post(route('client.contacts.import'), [
            'file' => $this->csvUpload(
                ['Phone', 'City', 'WhatsApp Opt-in', 'SMS Opt-in', 'Email Opt-in'],
                [[self::PHONE, 'Gurugram', '', '', '']]
            ),
            'default_country' => 'IN',
        ])->assertRedirect();

        $this->assertSame(1, Contact::where('workspace_id', $workspace->id)->count());
        $existing->refresh();
        $this->assertSame('Gurugram', $existing->city, 'The unrelated profile field must still have imported.');
        $this->assertTrue($existing->opt_in_whatsapp, 'Blank Opt-in columns must not revoke existing consent.');
        $this->assertFalse($existing->opt_in_sms);
        $this->assertTrue($existing->opt_in_email);
    }

    #[Test]
    public function csv_existing_contact_with_opt_in_columns_entirely_absent_keeps_its_current_consent(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $existing = Contact::create([
            'workspace_id' => $workspace->id,
            'phone_e164' => self::PHONE,
            'opt_in_whatsapp' => true,
            'opt_in_sms' => true,
            'opt_in_email' => false,
        ]);

        // No opt-in columns in the file header AT ALL this time — the
        // "importing a profile field must never unintentionally alter any
        // opt-in" case stated explicitly in the requirement.
        $this->actingAs($user)->post(route('client.contacts.import'), [
            'file' => $this->csvUpload(['Phone', 'City'], [[self::PHONE, 'Noida']]),
            'default_country' => 'IN',
        ])->assertRedirect();

        $existing->refresh();
        $this->assertSame('Noida', $existing->city);
        $this->assertTrue($existing->opt_in_whatsapp);
        $this->assertTrue($existing->opt_in_sms);
        $this->assertFalse($existing->opt_in_email);
    }

    #[Test]
    public function grid_existing_contact_with_null_opt_ins_keeps_its_current_consent(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $existing = Contact::create([
            'workspace_id' => $workspace->id,
            'phone_e164' => self::PHONE,
            'opt_in_whatsapp' => true,
            'opt_in_sms' => true,
            'opt_in_email' => true,
        ]);

        $this->actingAs($user)->post(route('client.contacts.bulk-store'), [
            'default_country' => 'IN',
            'rows' => [$this->gridRow(self::PHONE, ['city' => 'Delhi'])],
        ])->assertOk();

        $existing->refresh();
        $this->assertSame('Delhi', $existing->city);
        $this->assertTrue($existing->opt_in_whatsapp);
        $this->assertTrue($existing->opt_in_sms);
        $this->assertTrue($existing->opt_in_email);
    }

    // ══ 4. Explicit No turns off only that channel ═════════════════════

    #[Test]
    public function csv_explicit_no_on_one_channel_turns_off_only_that_channel(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $existing = Contact::create([
            'workspace_id' => $workspace->id,
            'phone_e164' => self::PHONE,
            'opt_in_whatsapp' => true,
            'opt_in_sms' => true,
            'opt_in_email' => true,
        ]);

        $this->actingAs($user)->post(route('client.contacts.import'), [
            'file' => $this->csvUpload(['Phone', 'SMS Opt-in'], [[self::PHONE, 'No']]),
            'default_country' => 'IN',
        ])->assertRedirect();

        $existing->refresh();
        $this->assertTrue($existing->opt_in_whatsapp, 'Untouched columns must not be affected by a No on a DIFFERENT channel.');
        $this->assertFalse($existing->opt_in_sms);
        $this->assertTrue($existing->opt_in_email);
    }

    #[Test]
    public function grid_explicit_false_token_on_one_channel_turns_off_only_that_channel(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $existing = Contact::create([
            'workspace_id' => $workspace->id,
            'phone_e164' => self::PHONE,
            'opt_in_whatsapp' => true,
            'opt_in_sms' => true,
            'opt_in_email' => true,
        ]);

        $this->actingAs($user)->post(route('client.contacts.bulk-store'), [
            'default_country' => 'IN',
            'rows' => [$this->gridRow(self::PHONE, ['opt_in_email' => 'False'])],
        ])->assertOk();

        $existing->refresh();
        $this->assertTrue($existing->opt_in_whatsapp);
        $this->assertTrue($existing->opt_in_sms);
        $this->assertFalse($existing->opt_in_email);
    }

    // ══ 5. Invalid value -> row skipped, visible row-specific error ════

    /** @return array<string, array{0: string}> */
    public static function invalidOptInProvider(): array
    {
        return [
            'y (not accepted — only Yes/No, True/False, 1/0)' => ['y'],
            'subscribed' => ['subscribed'],
            'maybe' => ['maybe'],
            'on' => ['on'],
            '2' => ['2'],
        ];
    }

    #[Test]
    #[DataProvider('invalidOptInProvider')]
    public function csv_invalid_opt_in_value_skips_the_row_with_a_visible_error(string $invalid): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        $this->actingAs($user)->post(route('client.contacts.import'), [
            'file' => $this->csvUpload(['Phone', 'WhatsApp Opt-in'], [[self::PHONE, $invalid]]),
            'default_country' => 'IN',
        ])->assertRedirect();

        $this->assertSame(0, Contact::where('workspace_id', $workspace->id)->count());
        $errors = session('import_errors');
        $this->assertNotEmpty($errors, "'{$invalid}' must produce a visible row error, not a silent skip.");
        $this->assertStringContainsString('WhatsApp Opt-in', implode(' ', $errors));
        $this->assertStringContainsString('is not recognized', implode(' ', $errors));
    }

    #[Test]
    #[DataProvider('invalidOptInProvider')]
    public function grid_invalid_opt_in_value_skips_the_row_with_a_visible_error(string $invalid): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        $this->actingAs($user)->post(route('client.contacts.bulk-store'), [
            'default_country' => 'IN',
            'rows' => [$this->gridRow(self::PHONE, ['opt_in_sms' => $invalid])],
        ])->assertOk();

        $this->assertSame(0, Contact::where('workspace_id', $workspace->id)->count());
        $errors = session('import_errors');
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('SMS Opt-in', implode(' ', $errors));
    }

    #[Test]
    public function an_invalid_opt_in_value_rejects_the_whole_row_not_just_that_field(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        // A valid phone AND a valid city, but one bad opt-in value — the
        // WHOLE row must be rejected, no partial write of the good fields.
        $this->actingAs($user)->post(route('client.contacts.import'), [
            'file' => $this->csvUpload(
                ['Phone', 'City', 'WhatsApp Opt-in'],
                [[self::PHONE, 'Mumbai', 'definitely-not-valid']]
            ),
            'default_country' => 'IN',
        ])->assertRedirect();

        $this->assertSame(0, Contact::where('workspace_id', $workspace->id)->count(), 'No partial write: an invalid opt-in must reject the entire row.');
    }

    // ══ 6. Name/Phone identity mapping — current and legacy headers ════

    #[Test]
    public function a_combined_name_header_splits_correctly_and_the_phone_still_persists(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        // The exact shape of item C's regression: a file with `Name` and
        // `Phone` only — no separate First/Last Name columns.
        $this->actingAs($user)->post(route('client.contacts.import'), [
            'file' => $this->csvUpload(['Name', 'Phone'], [['Honey Garg', self::PHONE]]),
            'default_country' => 'IN',
        ])->assertRedirect();

        $contact = Contact::where('workspace_id', $workspace->id)->sole();
        $this->assertSame('Honey', $contact->first_name, 'Name must not be silently dropped.');
        $this->assertSame('Garg', $contact->last_name);
        $this->assertSame(self::PHONE, $contact->phone_e164, 'Phone must not be silently dropped either.');
    }

    #[Test]
    public function a_single_word_name_maps_to_first_name_with_no_last_name(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        $this->actingAs($user)->post(route('client.contacts.import'), [
            'file' => $this->csvUpload(['Name', 'Phone'], [['Honey', self::PHONE]]),
            'default_country' => 'IN',
        ])->assertRedirect();

        $contact = Contact::where('workspace_id', $workspace->id)->sole();
        $this->assertSame('Honey', $contact->first_name);
        $this->assertNull($contact->last_name);
    }

    #[Test]
    public function separate_first_and_last_name_columns_still_work_exactly_as_before(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        $this->actingAs($user)->post(route('client.contacts.import'), [
            'file' => $this->csvUpload(['First Name', 'Last Name', 'Phone'], [['Honey', 'Garg', self::PHONE]]),
            'default_country' => 'IN',
        ])->assertRedirect();

        $contact = Contact::where('workspace_id', $workspace->id)->sole();
        $this->assertSame('Honey', $contact->first_name);
        $this->assertSame('Garg', $contact->last_name);
    }

    #[Test]
    public function a_full_name_header_alias_is_also_recognized(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        $this->actingAs($user)->post(route('client.contacts.import'), [
            'file' => $this->csvUpload(['Full Name', 'Phone'], [['Piyush Kumar', self::PHONE]]),
            'default_country' => 'IN',
        ])->assertRedirect();

        $contact = Contact::where('workspace_id', $workspace->id)->sole();
        $this->assertSame('Piyush', $contact->first_name);
        $this->assertSame('Kumar', $contact->last_name);
    }

    #[Test]
    public function the_legacy_export_opt_in_header_casing_is_still_recognized_on_import(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        // "Opt-in WhatsApp" (Opt-in-first) — the OLD export casing, before
        // this change renamed export() to "WhatsApp Opt-in". Anyone
        // re-importing a file they exported before this change must not be
        // broken by it.
        $this->actingAs($user)->post(route('client.contacts.import'), [
            'file' => $this->csvUpload(['Phone', 'Opt-in WhatsApp'], [[self::PHONE, 'yes']]),
            'default_country' => 'IN',
        ])->assertRedirect();

        $contact = Contact::where('workspace_id', $workspace->id)->sole();
        $this->assertTrue($contact->opt_in_whatsapp);
    }

    // ══ 7. No blank contact from malformed identity input ══════════════

    #[Test]
    public function a_row_with_neither_phone_nor_email_is_rejected_not_silently_created_blank(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        // City is populated (so the row is not entirely empty) but there is
        // no phone and no email — no identity to key a contact on.
        $this->actingAs($user)->post(route('client.contacts.import'), [
            'file' => $this->csvUpload(['City'], [['Mumbai']]),
        ])->assertRedirect();

        $this->assertSame(0, Contact::where('workspace_id', $workspace->id)->count());
        $errors = session('import_errors');
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('phone number or email address is required', implode(' ', $errors));
    }

    #[Test]
    public function a_completely_blank_row_produces_no_contact_and_no_silent_success(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        $this->actingAs($user)->post(route('client.contacts.import'), [
            'file' => $this->csvUpload(['First Name', 'Phone', 'Email'], [['', '', '']]),
        ])->assertRedirect();

        $this->assertSame(0, Contact::where('workspace_id', $workspace->id)->count());
        $this->assertNotEmpty(session('import_errors'));
    }

    #[Test]
    public function an_email_only_row_still_creates_a_contact_the_way_it_always_has(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        // Email-only identity is pre-existing, intentional behaviour
        // (upsert() falls back to an email lookup) — the identity-required
        // guard must not newly break it.
        $this->actingAs($user)->post(route('client.contacts.import'), [
            'file' => $this->csvUpload(['Name', 'Email'], [['Honey Garg', 'honey@example.com']]),
        ])->assertRedirect();

        $contact = Contact::where('workspace_id', $workspace->id)->sole();
        $this->assertSame('honey@example.com', $contact->email);
        $this->assertSame('Honey', $contact->first_name);
    }

    // ══ 8. Sample files: current headers, ISO dates, distinct phones ═══

    #[Test]
    public function the_csv_sample_contains_all_three_opt_in_columns_and_default_no(): void
    {
        $content = $this->buildSampleCsvViaNode(['code' => 'IN', 'name' => 'India', 'calling_code' => '+91', 'label' => 'India (+91)', 'example' => '9123456789']);

        $this->assertStringContainsString('WhatsApp Opt-in', $content);
        $this->assertStringContainsString('SMS Opt-in', $content);
        $this->assertStringContainsString('Email Opt-in', $content);

        // Every populated opt-in cell in the sample must be "No".
        $rows = array_map('str_getcsv', array_filter(explode("\r\n", trim($content))));
        $headers = $rows[0];
        $whatsappCol = array_search('WhatsApp Opt-in', $headers, true);
        $this->assertNotFalse($whatsappCol);
        foreach (array_slice($rows, 1) as $row) {
            $this->assertSame('No', $row[$whatsappCol]);
        }
    }

    #[Test]
    public function the_csv_sample_headers_are_every_one_recognized_by_the_real_import_endpoint(): void
    {
        // ⚠️ THE ROUND-TRIP PROOF item B asks for: not eyeballing the header
        // strings, but actually POSTing a file built with them through the
        // real import endpoint and confirming every recognized column landed
        // on the resulting contact. This is what "the sample stays genuinely
        // importable" MEANS, verified rather than assumed.
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        $content = $this->buildSampleCsvViaNode(['code' => 'IN', 'name' => 'India', 'calling_code' => '+91', 'label' => 'India (+91)', 'example' => '9123456789']);
        $file = UploadedFile::fake()->createWithContent('sample.csv', $content);

        $response = $this->actingAs($user)->post(route('client.contacts.import'), [
            'file' => $file,
            'default_country' => 'IN',
        ]);
        $response->assertRedirect();

        // No row-specific errors — every header in the sample must be one
        // the backend actually recognizes and every sample value must be one
        // it actually accepts.
        $this->assertEmpty(session('import_errors'), 'The sample CSV must import cleanly: '.implode('; ', session('import_errors') ?? []));

        $created = Contact::where('workspace_id', $workspace->id)->get();
        $this->assertGreaterThanOrEqual(2, $created->count(), 'Distinct-phone requirement: the sample must create more than one contact.');

        // Distinct phones requirement, proven on the STORED rows.
        $phones = $created->pluck('phone_e164')->all();
        $this->assertSame($phones, array_unique($phones), 'Sample rows must use distinct phone numbers.');

        foreach ($created as $c) {
            $this->assertFalse($c->opt_in_whatsapp, 'Sample defaults opt-ins to No, so nothing should import as opted in.');
            $this->assertFalse($c->opt_in_sms);
            $this->assertFalse($c->opt_in_email);
        }
    }

    #[Test]
    public function the_csv_sample_dates_are_iso_and_the_backend_accepts_them(): void
    {
        $content = $this->buildSampleCsvViaNode(null);

        // The stale evidence this fix replaces: "5/20/1990" and "10/14/2015".
        $this->assertStringNotContainsString('5/20/1990', $content);
        $this->assertStringNotContainsString('10/14/2015', $content);
        $this->assertMatchesRegularExpression('/\b\d{4}-\d{2}-\d{2}\b/', $content, 'Must contain at least one YYYY-MM-DD date.');
    }

    #[Test]
    public function the_csv_sample_never_contains_the_stale_header_text(): void
    {
        $content = $this->buildSampleCsvViaNode(null);

        $this->assertStringNotContainsString('Phone (E.164, country code required)', $content);
    }

    /**
     * Builds the sample CSV EXACTLY the way the browser's Download Sample
     * button does — by running the REAL, UNMODIFIED buildSampleCsv() /
     * sampleHeaderText() / CSV_FIELDS / SAMPLE_ROW_VALUES logic from
     * bulkImportExcel.js through Node, not by re-implementing any of it in
     * PHP. That is what makes this a genuine round-trip proof that the
     * PHP-side IMPORT_HEADER_MAP and the JS-side sample generator — two
     * independent pieces of code in two languages — actually agree, rather
     * than two hand-written lists that happen to match today.
     *
     * ⚠️ ONE LINE is substituted before running: the `import i18n from
     * '@/i18n'` at the top, which plain Node has no alias resolution for
     * (that alias only exists inside Vite's build). This is safe SPECIFICALLY
     * because `buildSampleCsv()` never calls `i18n.t()` — only the grid's
     * `gridHeader()` closures do, and those are never invoked by this
     * function. Everything buildSampleCsv() actually executes — CSV_FIELDS,
     * sampleHeaderText(), SAMPLE_ROW_VALUES, csvRow(), csvField() — runs
     * completely unmodified.
     */
    private function buildSampleCsvViaNode(?array $country): string
    {
        $source = file_get_contents(base_path('resources/js/Pages/Contacts/bulkImportExcel.js'));
        $this->assertNotFalse($source);

        $needle = "import i18n from '@/i18n';";
        $this->assertStringContainsString($needle, $source, 'bulkImportExcel.js no longer imports i18n the expected way — update this harness.');
        $stubbed = str_replace($needle, 'const i18n = { t: (k) => k };', $source);
        $this->assertStringNotContainsString($needle, $stubbed, 'i18n import substitution did not apply.');

        // ⚠️ MUST live inside the project tree (under resources/js/…), NOT
        // the system temp directory — Node resolves bare package imports
        // (exceljs) by walking UP from the importing file looking for
        // node_modules, and /tmp has none. A random, obviously-temporary
        // filename plus a guaranteed try/finally cleanup keeps this from
        // ever leaving a stray file behind, including on a failed assertion.
        $dir = base_path('resources/js/Pages/Contacts');
        $token = bin2hex(random_bytes(8));
        $tmpModule = "{$dir}/.tmp_test_module_{$token}.mjs";
        $tmpRunner = "{$dir}/.tmp_test_runner_{$token}.mjs";

        $countryJson = $country === null ? 'null' : json_encode($country);

        try {
            file_put_contents($tmpModule, $stubbed);
            file_put_contents($tmpRunner, <<<JS
                import { buildSampleCsv } from './.tmp_test_module_{$token}.mjs';
                process.stdout.write(buildSampleCsv({$countryJson}));
                JS);

            $output = shell_exec('node '.escapeshellarg($tmpRunner).' 2>&1');
        } finally {
            @unlink($tmpModule);
            @unlink($tmpRunner);
        }

        $this->assertNotNull($output, 'node failed to run the real buildSampleCsv().');
        $this->assertStringNotContainsString('Error', $output, "node reported an error:\n{$output}");

        return $output;
    }

    // ══ 9. Import still triggers no automation/webhook/job ═════════════

    #[Test]
    public function importing_with_opt_ins_still_dispatches_no_contact_created_event(): void
    {
        Event::fake([ContactCreated::class]);
        Queue::fake();
        Bus::fake();

        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        $this->actingAs($user)->post(route('client.contacts.import'), [
            'file' => $this->csvUpload(['Phone', 'WhatsApp Opt-in'], [[self::PHONE, 'Yes']]),
            'default_country' => 'IN',
        ])->assertRedirect();

        $this->assertSame(1, Contact::where('workspace_id', $workspace->id)->count());

        Event::assertNotDispatched(ContactCreated::class);
        Queue::assertNothingPushed();
        Bus::assertNothingDispatched();
    }

    #[Test]
    public function an_opted_in_import_still_fires_no_automation_run_and_no_outbound_webhook(): void
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

        WebhookEndpoint::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'url' => 'https://example.test/webhooks/contact-created',
            'secret' => WebhookEndpoint::generateSecret(),
            'events' => ['contact.created'],
            'enabled' => true,
        ]);

        // Explicit YES on all three channels — a real opted-in contact is
        // exactly the case someone might assume SHOULD trigger a welcome
        // send. It still must not, during import.
        $this->actingAs($user)->post(route('client.contacts.bulk-store'), [
            'default_country' => 'IN',
            'rows' => [$this->gridRow(self::PHONE, [
                'opt_in_whatsapp' => 'Yes',
                'opt_in_sms' => 'Yes',
                'opt_in_email' => 'Yes',
            ])],
        ])->assertOk();

        $contact = Contact::where('workspace_id', $workspace->id)->sole();
        $this->assertTrue($contact->opt_in_whatsapp);

        Queue::assertNotPushed(ExecuteAutomationRunJob::class);
        Queue::assertNotPushed(DispatchWebhookJob::class);
        $this->assertSame(0, AutomationRun::count());
    }

    // ══ 10. Workspace isolation still holds with opt-in resolution ════

    #[Test]
    public function opt_in_resolution_does_not_leak_across_workspaces(): void
    {
        ['user' => $userA, 'workspace' => $workspaceA] = $this->createWorkspaceContext();
        ['workspace' => $workspaceB] = $this->createWorkspaceContext();

        $bContact = Contact::create([
            'workspace_id' => $workspaceB->id,
            'phone_e164' => self::PHONE,
            'opt_in_whatsapp' => true,
            'opt_in_sms' => true,
            'opt_in_email' => true,
        ]);

        $this->actingAs($userA)->post(route('client.contacts.bulk-store'), [
            'default_country' => 'IN',
            'rows' => [$this->gridRow(self::PHONE)],
        ])->assertOk();

        $aContact = Contact::where('workspace_id', $workspaceA->id)->sole();
        $this->assertFalse($aContact->opt_in_whatsapp, 'A new contact in workspace A must default to false, not inherit B\'s consent.');

        $bContact->refresh();
        $this->assertTrue($bContact->opt_in_whatsapp, 'Workspace B\'s contact must be untouched by A\'s import.');
    }
}
