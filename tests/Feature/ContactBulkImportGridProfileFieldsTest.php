<?php

namespace Tests\Feature;

use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\ContactTag;
use App\Modules\Shared\Models\Segment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The actual Contact import UI is the XLSX/ExcelJS Bulk Import grid
 * (Contacts/BulkImport.jsx + bulkImportExcel.js), not the CSV upload —
 * these tests exercise its backend (client.contacts.bulk-store →
 * ContactService::importGridRows()) with payloads shaped exactly like
 * bulkImportExcel.js's matrixToPayload() produces: every row always
 * carries all ten keys, blank cells arrive as `null` (never omitted),
 * tag/segment are already resolved to ids by the frontend.
 *
 * The XLSX generation/parsing itself (bulkImportExcel.js, ExcelJS,
 * Handsontable) is client-side JS with no executable test runner in this
 * environment (vitest is not installed) — verified separately via a real
 * ExcelJS round trip against the actual downloadSampleWorkbook()/
 * parseWorkbookToMatrix() logic (see the verification report), not
 * skipped silently.
 */
class ContactBulkImportGridProfileFieldsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
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

    #[Test]
    public function bulk_store_creates_a_contact_with_all_six_profile_values(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        $this->actingAs($user)->post(route('client.contacts.bulk-store'), [
            'rows' => [$this->gridRow('+919810000001', [
                'name' => 'Asha Rao',
                'gender' => 'Female',
                'birthday' => '1992-04-16',
                'anniversary_date' => '2018-11-03',
                'city' => 'Mumbai',
                'state' => 'Maharashtra',
                'postal_code' => '400001',
            ])],
        ])->assertOk();

        $this->assertDatabaseHas('contacts', [
            'workspace_id' => $workspace->id,
            'phone_e164' => '+919810000001',
            'first_name' => 'Asha',
            'last_name' => 'Rao',
            'gender' => 'female',
            'birthday' => '1992-04-16',
            'anniversary_date' => '2018-11-03',
            'city' => 'Mumbai',
            'state' => 'Maharashtra',
            'postal_code' => '400001',
        ]);
    }

    #[Test]
    public function bulk_store_updates_an_existing_contact_with_valid_provided_values(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $contact = Contact::factory()->create(['workspace_id' => $workspace->id, 'phone_e164' => '+919810000002']);

        $this->actingAs($user)->post(route('client.contacts.bulk-store'), [
            'rows' => [$this->gridRow('+919810000002', ['city' => 'Pune', 'state' => 'Maharashtra'])],
        ])->assertOk();

        $contact->refresh();
        $this->assertSame('Pune', $contact->city);
        $this->assertSame('Maharashtra', $contact->state);
    }

    #[Test]
    public function existing_grid_payload_stored_via_bulk_store_re_exports_correctly_via_csv(): void
    {
        ['user' => $user] = $this->createWorkspaceContext();

        $this->actingAs($user)->post(route('client.contacts.bulk-store'), [
            'rows' => [$this->gridRow('+919810000003', [
                'gender' => 'Non-binary / Other',
                'birthday' => '1990-05-20',
                'anniversary_date' => '2015-10-14',
                'city' => 'Bengaluru',
                'state' => 'Karnataka',
                'postal_code' => '560001',
            ])],
        ])->assertOk();

        $csv = $this->actingAs($user)->get(route('client.contacts.export'))->getContent();
        $lines = array_filter(explode("\n", $csv), fn ($l) => trim($l) !== '');
        $rows = array_map('str_getcsv', $lines);
        $headers = array_shift($rows);
        $row = array_combine($headers, $rows[0]);

        $this->assertSame('Non-binary / Other', $row['Gender']);
        $this->assertSame('1990-05-20', $row['Birthday']);
        $this->assertSame('2015-10-14', $row['Anniversary Date']);
        $this->assertSame('Bengaluru', $row['City']);
        $this->assertSame('Karnataka', $row['State']);
        $this->assertSame('560001', $row['Postal Code / PIN Code']);
    }

    #[Test]
    public function invalid_gender_and_date_values_produce_row_specific_errors_matching_the_original_grid_row(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        // Rows 1-2 are blank spare grid rows (no phone) — the reported row
        // number for the real error below must still reflect its ACTUAL
        // position in the grid (Row 4), not its position among only the
        // non-blank rows (which would be "Row 2").
        $response = $this->actingAs($user)->post(route('client.contacts.bulk-store'), [
            'rows' => [
                $this->gridRow(''),
                $this->gridRow(''),
                $this->gridRow('+919810000004', ['gender' => 'Alien']),
                $this->gridRow('+919810000005', ['birthday' => '16/04/1992']),
            ],
        ]);

        $response->assertOk();

        $errors = session('import_errors');
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('Row 3', implode(' ', $errors));
        $this->assertStringContainsString('Gender', implode(' ', $errors));
        $this->assertStringContainsString('Row 4', implode(' ', $errors));
        $this->assertStringContainsString('YYYY-MM-DD', implode(' ', $errors));

        $this->assertSame(0, Contact::where('workspace_id', $workspace->id)
            ->whereIn('phone_e164', ['+919810000004', '+919810000005'])
            ->count());
    }

    #[Test]
    public function a_blank_profile_cell_does_not_erase_an_existing_value(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $contact = Contact::factory()->create([
            'workspace_id' => $workspace->id,
            'phone_e164' => '+919810000006',
            'city' => 'Mumbai',
            'gender' => 'female',
        ]);

        $this->actingAs($user)->post(route('client.contacts.bulk-store'), [
            'rows' => [$this->gridRow('+919810000006', ['city' => null])],
        ])->assertOk();

        $contact->refresh();
        $this->assertSame('Mumbai', $contact->city, 'A blank grid cell must not erase the existing value.');
        $this->assertSame('female', $contact->gender);
    }

    #[Test]
    public function bulk_store_stays_workspace_isolated(): void
    {
        ['user' => $userA, 'workspace' => $workspaceA] = $this->createWorkspaceContext();
        ['workspace' => $workspaceB] = $this->createWorkspaceContext();
        Contact::factory()->create(['workspace_id' => $workspaceB->id, 'phone_e164' => '+919810000007', 'city' => 'Delhi']);

        $this->actingAs($userA)->post(route('client.contacts.bulk-store'), [
            'rows' => [$this->gridRow('+919810000007', ['city' => 'Pune'])],
        ])->assertOk();

        $this->assertDatabaseHas('contacts', ['workspace_id' => $workspaceB->id, 'phone_e164' => '+919810000007', 'city' => 'Delhi']);
        $this->assertDatabaseHas('contacts', ['workspace_id' => $workspaceA->id, 'phone_e164' => '+919810000007', 'city' => 'Pune']);
    }

    #[Test]
    public function bulk_store_preserves_consent_tags_segments_custom_fields_and_dispatches_no_job(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $tag = ContactTag::create(['workspace_id' => $workspace->id, 'name' => 'VIP']);
        $segment = Segment::create(['workspace_id' => $workspace->id, 'name' => 'Loyal', 'type' => 'static']);
        $contact = Contact::factory()->create([
            'workspace_id' => $workspace->id,
            'phone_e164' => '+919810000008',
            'custom_fields' => ['tier' => 'gold'],
            'whatsapp_consent_source' => 'signup',
            'digital_bill_opt_out_source' => 'customer_request',
        ]);
        $contact->tags()->attach($tag);
        $contact->segments()->attach($segment);

        Queue::fake();

        $this->actingAs($user)->post(route('client.contacts.bulk-store'), [
            'rows' => [$this->gridRow('+919810000008', ['city' => 'Chennai'])],
        ])->assertOk();

        Queue::assertNothingPushed();
        $contact->refresh();

        $this->assertSame('Chennai', $contact->city);
        $this->assertSame(['tier' => 'gold'], $contact->custom_fields);
        $this->assertSame('signup', $contact->whatsapp_consent_source);
        $this->assertSame('customer_request', $contact->digital_bill_opt_out_source);
        $this->assertTrue($contact->tags->contains($tag));
        $this->assertTrue($contact->segments->contains($segment));
    }

    #[Test]
    public function existing_tag_and_segment_assignment_still_works_unchanged(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $tag = ContactTag::create(['workspace_id' => $workspace->id, 'name' => 'VIP']);
        $segment = Segment::create(['workspace_id' => $workspace->id, 'name' => 'Loyal', 'type' => 'static']);

        $this->actingAs($user)->post(route('client.contacts.bulk-store'), [
            'rows' => [$this->gridRow('+919810000009', ['tag_id' => $tag->id, 'segment_id' => $segment->id])],
        ])->assertOk();

        $contact = Contact::where('workspace_id', $workspace->id)->where('phone_e164', '+919810000009')->firstOrFail();
        $this->assertTrue($contact->tags->contains($tag));
        $this->assertTrue($contact->segments->contains($segment));
    }
}
