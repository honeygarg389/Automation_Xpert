<?php

namespace Tests\Feature;

use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\ContactTag;
use App\Modules\Shared\Models\Segment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Bug report: the six Personal Details fields (gender, birthday,
 * anniversary_date, city, state, postal_code) work in the Contact dashboard
 * form but are missing from CSV export and cannot be updated through CSV
 * import.
 *
 * ⚠️ THE ROOT CAUSE WAS BIGGER THAN "6 FIELDS MISSING": export()'s headers
 * ("First Name", "Phone", ...) never matched Contact::$fillable
 * (first_name, phone_e164, ...), and import() passed raw CSV header text
 * straight through as array keys with zero mapping — so re-importing an
 * exported file silently created blank-identity contacts for EVERY field,
 * not just the six new ones. Fixing only the six new fields' import while
 * leaving that mismatch in place would make the round-trip requirement
 * (export -> import without corruption) impossible to satisfy, so these
 * tests exercise the whole header set the fix now maps, not just the six
 * new columns in isolation.
 */
class ContactImportExportProfileFieldsTest extends TestCase
{
    use RefreshDatabase;

    private const EXPORT_HEADERS = [
        'First Name', 'Last Name', 'Phone', 'Email', 'Tags', 'WhatsApp Opt-in', 'SMS Opt-in', 'Email Opt-in', 'Created At',
        'Gender', 'Birthday', 'Anniversary Date', 'City', 'State', 'Postal Code / PIN Code',
    ];

    /** @return array<int, array<string, string>> */
    private function parseCsv(string $csv): array
    {
        $lines = array_filter(explode("\n", $csv), fn ($l) => trim($l) !== '');
        $rows = array_map('str_getcsv', $lines);
        $headers = array_shift($rows);

        return array_map(fn ($row) => array_combine($headers, $row), $rows);
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     */
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

    // ══ Export ═════════════════════════════════════════════════════════

    #[Test]
    public function export_contains_all_six_new_headers_and_correct_populated_values(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        Contact::factory()->create([
            'workspace_id' => $workspace->id,
            'first_name' => 'Asha',
            'phone_e164' => '+919810000001',
            'gender' => 'female',
            'birthday' => '1992-04-16',
            'anniversary_date' => '2018-11-03',
            'city' => 'Mumbai',
            'state' => 'Maharashtra',
            'postal_code' => '400001',
        ]);

        $response = $this->actingAs($user)->get(route('client.contacts.export'));
        $response->assertOk();

        $rows = $this->parseCsv($response->getContent());
        $this->assertSame(self::EXPORT_HEADERS, array_keys($rows[0]));

        $row = $rows[0];
        $this->assertSame('Female', $row['Gender']);
        $this->assertSame('1992-04-16', $row['Birthday']);
        $this->assertSame('2018-11-03', $row['Anniversary Date']);
        $this->assertSame('Mumbai', $row['City']);
        $this->assertSame('Maharashtra', $row['State']);
        $this->assertSame('400001', $row['Postal Code / PIN Code']);
    }

    #[Test]
    public function blank_personal_details_export_as_blank_cells(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        Contact::factory()->create(['workspace_id' => $workspace->id, 'first_name' => 'NoProfile']);

        $response = $this->actingAs($user)->get(route('client.contacts.export'));
        $row = $this->parseCsv($response->getContent())[0];

        foreach (['Gender', 'Birthday', 'Anniversary Date', 'City', 'State', 'Postal Code / PIN Code'] as $header) {
            $this->assertSame('', $row[$header], "{$header} must export blank, not a literal null/placeholder.");
        }
    }

    // ══ Import — create / update ══════════════════════════════════════

    #[Test]
    public function import_creates_a_contact_with_all_six_values(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $file = $this->csvUpload(
            ['First Name', 'Phone', 'Gender', 'Birthday', 'Anniversary Date', 'City', 'State', 'Postal Code / PIN Code'],
            [['Asha', '+919810000002', 'Female', '1992-04-16', '2018-11-03', 'Mumbai', 'Maharashtra', '400001']]
        );

        $this->actingAs($user)->post(route('client.contacts.import'), ['file' => $file])->assertRedirect();

        $this->assertDatabaseHas('contacts', [
            'workspace_id' => $workspace->id,
            'phone_e164' => '+919810000002',
            'gender' => 'female',
            'birthday' => '1992-04-16',
            'anniversary_date' => '2018-11-03',
            'city' => 'Mumbai',
            'state' => 'Maharashtra',
            'postal_code' => '400001',
        ]);
    }

    #[Test]
    public function import_updates_an_existing_contact_with_valid_provided_values(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $contact = Contact::factory()->create(['workspace_id' => $workspace->id, 'phone_e164' => '+919810000003']);

        $file = $this->csvUpload(
            ['Phone', 'City', 'State'],
            [['+919810000003', 'Pune', 'Maharashtra']]
        );

        $this->actingAs($user)->post(route('client.contacts.import'), ['file' => $file])->assertRedirect();

        $contact->refresh();
        $this->assertSame('Pune', $contact->city);
        $this->assertSame('Maharashtra', $contact->state);
    }

    #[Test]
    public function exported_data_can_be_imported_back_without_value_corruption(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $contact = Contact::factory()->create([
            'workspace_id' => $workspace->id,
            'first_name' => 'Asha',
            'last_name' => 'Rao',
            'phone_e164' => '+919810000004',
            'email' => 'asha@example.com',
            'gender' => 'female',
            'birthday' => '1992-04-16',
            'anniversary_date' => '2018-11-03',
            'city' => 'Mumbai',
            'state' => 'Maharashtra',
            'postal_code' => '400001',
        ]);

        $csv = $this->actingAs($user)->get(route('client.contacts.export'))->getContent();
        $file = UploadedFile::fake()->createWithContent('contacts.csv', $csv);

        $this->actingAs($user)->post(route('client.contacts.import'), ['file' => $file])->assertRedirect();

        $this->assertSame(1, Contact::where('workspace_id', $workspace->id)->count(),
            'Re-importing an exported file must UPDATE the same contact, never duplicate it.');

        $contact->refresh();
        $this->assertSame('Asha', $contact->first_name);
        $this->assertSame('Rao', $contact->last_name);
        $this->assertSame('female', $contact->gender);
        $this->assertSame('1992-04-16', $contact->birthday->toDateString());
        $this->assertSame('2018-11-03', $contact->anniversary_date->toDateString());
        $this->assertSame('Mumbai', $contact->city);
        $this->assertSame('Maharashtra', $contact->state);
        $this->assertSame('400001', $contact->postal_code);
    }

    // ══ Import — validation / friendly errors ═════════════════════════

    #[Test]
    public function invalid_gender_and_ambiguous_or_future_dates_produce_row_specific_errors(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $file = $this->csvUpload(
            ['Phone', 'Gender', 'Birthday', 'Anniversary Date'],
            [
                ['+919810000005', 'Alien', '', ''],
                ['+919810000006', '', '16/04/1992', ''],
                ['+919810000007', '', '', now()->addDay()->toDateString()],
            ]
        );

        $response = $this->actingAs($user)->post(route('client.contacts.import'), ['file' => $file]);
        $response->assertRedirect();

        $errors = session('import_errors');
        $this->assertNotEmpty($errors);

        // ⚠️ Row numbers are FILE LINE NUMBERS: the first data row is line 2,
        // because line 1 is the header. This test previously asserted Row 1/2/3
        // for these same three rows — one less than the line the admin sees
        // when they open the CSV, which made a row-specific error point at the
        // wrong row (and at the header, for the first one). Corrected alongside
        // the phone-normalization work, which is what put row-specific errors
        // in front of users often enough for the off-by-one to matter.
        $this->assertStringContainsString('Row 2', implode(' ', $errors));
        $this->assertStringContainsString('Gender', implode(' ', $errors));
        $this->assertStringContainsString('Row 3', implode(' ', $errors));
        $this->assertStringContainsString('YYYY-MM-DD', implode(' ', $errors));
        $this->assertStringContainsString('Row 4', implode(' ', $errors));
        $this->assertStringContainsString('future', implode(' ', $errors));

        // No partial writes: none of the three invalid rows created a contact.
        $this->assertSame(0, Contact::where('workspace_id', $workspace->id)
            ->whereIn('phone_e164', ['+919810000005', '+919810000006', '+919810000007'])
            ->count());
    }

    #[Test]
    public function omitted_or_blank_personal_details_columns_do_not_erase_existing_values(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $contact = Contact::factory()->create([
            'workspace_id' => $workspace->id,
            'phone_e164' => '+919810000008',
            'city' => 'Mumbai',
            'state' => 'Maharashtra',
            'gender' => 'female',
        ]);

        // "State" column omitted entirely; "City" present but blank.
        $file = $this->csvUpload(
            ['Phone', 'City'],
            [['+919810000008', '']]
        );

        $this->actingAs($user)->post(route('client.contacts.import'), ['file' => $file])->assertRedirect();

        $contact->refresh();
        $this->assertSame('Mumbai', $contact->city, 'A blank cell in a present column must not erase the existing value.');
        $this->assertSame('Maharashtra', $contact->state, 'An omitted column must never be touched.');
        $this->assertSame('female', $contact->gender);
    }

    // ══ Workspace isolation ════════════════════════════════════════════

    #[Test]
    public function import_and_export_stay_workspace_isolated(): void
    {
        ['user' => $userA, 'workspace' => $workspaceA] = $this->createWorkspaceContext();
        ['workspace' => $workspaceB] = $this->createWorkspaceContext();

        Contact::factory()->create(['workspace_id' => $workspaceA->id, 'city' => 'Mumbai', 'phone_e164' => '+919810000009']);
        Contact::factory()->create(['workspace_id' => $workspaceB->id, 'city' => 'Delhi', 'phone_e164' => '+919810000010']);

        $rows = $this->parseCsv($this->actingAs($userA)->get(route('client.contacts.export'))->getContent());
        $this->assertCount(1, $rows);
        $this->assertSame('Mumbai', $rows[0]['City']);

        // Importing under workspace A must never match/update workspace B's contact.
        $file = $this->csvUpload(['Phone', 'City'], [['+919810000010', 'Pune']]);
        $this->actingAs($userA)->post(route('client.contacts.import'), ['file' => $file])->assertRedirect();

        $this->assertDatabaseHas('contacts', ['workspace_id' => $workspaceB->id, 'phone_e164' => '+919810000010', 'city' => 'Delhi']);
        $this->assertDatabaseMissing('contacts', ['workspace_id' => $workspaceB->id, 'city' => 'Pune']);
        // A new, separate contact is created in workspace A instead — never a cross-workspace update.
        $this->assertDatabaseHas('contacts', ['workspace_id' => $workspaceA->id, 'phone_e164' => '+919810000010', 'city' => 'Pune']);
    }

    // ══ No side effects beyond the six fields ═════════════════════════

    #[Test]
    public function importing_profile_fields_preserves_consent_tags_segments_custom_fields_and_dispatches_no_job(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $tag = ContactTag::create(['workspace_id' => $workspace->id, 'name' => 'VIP']);
        $segment = Segment::create(['workspace_id' => $workspace->id, 'name' => 'Loyal', 'type' => 'static']);
        $contact = Contact::factory()->create([
            'workspace_id' => $workspace->id,
            'phone_e164' => '+919810000011',
            'source' => 'lead_form',
            'lead_id' => 123,
            'custom_fields' => ['tier' => 'gold'],
            'opt_in_whatsapp' => true,
            'opt_in_sms' => false,
            'whatsapp_consent_at' => '2026-01-01 10:00:00',
            'whatsapp_consent_source' => 'signup',
            'digital_bill_opted_out_at' => '2026-02-01 10:00:00',
            'digital_bill_opt_out_source' => 'customer_request',
        ]);
        $contact->tags()->attach($tag);
        $contact->segments()->attach($segment);

        Queue::fake();

        $file = $this->csvUpload(['Phone', 'City'], [['+919810000011', 'Chennai']]);
        $this->actingAs($user)->post(route('client.contacts.import'), ['file' => $file])->assertRedirect();

        Queue::assertNothingPushed();
        $contact->refresh();

        $this->assertSame('Chennai', $contact->city);
        // ⚠️ `source` becoming 'import' here is PRE-EXISTING, unmodified
        // bulkImport() behaviour (array_merge(['source' => $source], $row)
        // was already there before this fix) — not something this feature
        // changes, and not one of the fields requirement 8 protects
        // (consent/opt-out/tags/segments/custom_fields/channel prefs).
        // Pinned here as the real, current behaviour, not the dashboard
        // form's (which never touches `source` at all on update).
        $this->assertSame('import', $contact->source);
        $this->assertSame(123, $contact->lead_id);
        $this->assertSame(['tier' => 'gold'], $contact->custom_fields);
        $this->assertTrue($contact->opt_in_whatsapp);
        $this->assertFalse($contact->opt_in_sms);
        $this->assertSame('signup', $contact->whatsapp_consent_source);
        $this->assertSame('customer_request', $contact->digital_bill_opt_out_source);
        $this->assertTrue($contact->tags->contains($tag));
        $this->assertTrue($contact->segments->contains($segment));
    }

    // ══ Existing behaviour unchanged ═══════════════════════════════════

    #[Test]
    public function existing_export_columns_and_values_remain_unchanged(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $contact = Contact::factory()->create([
            'workspace_id' => $workspace->id,
            'first_name' => 'Asha',
            'last_name' => 'Rao',
            'phone_e164' => '+919810000012',
            'email' => 'asha@example.com',
            'opt_in_whatsapp' => true,
            'opt_in_sms' => false,
            'opt_in_email' => true,
        ]);
        $tag = ContactTag::create(['workspace_id' => $workspace->id, 'name' => 'VIP']);
        $contact->tags()->attach($tag);

        $row = $this->parseCsv($this->actingAs($user)->get(route('client.contacts.export'))->getContent())[0];

        $this->assertSame('Asha', $row['First Name']);
        $this->assertSame('Rao', $row['Last Name']);
        $this->assertSame('+919810000012', $row['Phone']);
        $this->assertSame('asha@example.com', $row['Email']);
        $this->assertSame('VIP', $row['Tags']);
        $this->assertSame('yes', $row['WhatsApp Opt-in']);
        $this->assertSame('no', $row['SMS Opt-in']);
        $this->assertSame('yes', $row['Email Opt-in']);
        $this->assertNotSame('', $row['Created At']);
    }

    #[Test]
    public function importing_core_identity_columns_alone_still_works_as_before(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $file = $this->csvUpload(
            ['First Name', 'Last Name', 'Phone', 'Email'],
            [['Priya', 'Shah', '+919810000013', 'priya@example.com']]
        );

        $this->actingAs($user)->post(route('client.contacts.import'), ['file' => $file])->assertRedirect();

        $this->assertDatabaseHas('contacts', [
            'workspace_id' => $workspace->id,
            'first_name' => 'Priya',
            'last_name' => 'Shah',
            'phone_e164' => '+919810000013',
            'email' => 'priya@example.com',
        ]);
    }
}
