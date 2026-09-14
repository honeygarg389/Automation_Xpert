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
 * Adds an optional Email column to both import surfaces, immediately after
 * Phone. Every rule here is about IDENTITY, not just "does the email save":
 * a row can now be identified by phone, by email, or by both — and once both
 * are possible, the interesting question is what happens when they disagree.
 *
 * ─── The rules this file exists to prove ───────────────────────────────────
 *
 *   phone + email, no conflict   safe: writes both onto the phone-matched
 *                                 (or new) contact.
 *
 *   phone + email, CONFLICT      the email belongs to some OTHER contact in
 *                                 the workspace -> reject the row. Two
 *                                 contacts are never silently merged.
 *
 *   email only, one match        update that contact.
 *   email only, no match         create a new contact.
 *   email only, AMBIGUOUS        more than one existing contact already
 *                                 shares that email -> reject rather than
 *                                 guess.
 *
 *   blank/omitted Email on an
 *   existing contact              never erases the current email.
 *
 *   Email Opt-in                  entirely independent — supplying an email
 *                                 never implies opt-in, and an explicit
 *                                 opt-in value is honoured regardless of
 *                                 whether this row also carries an email.
 *
 * See ContactService::normalizeEmailInput() / resolveContactIdentity().
 */
class ContactImportEmailIdentityTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '+918630026021';

    private const PHONE_2 = '+918630026029';

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
    private function gridRow(array $overrides = []): array
    {
        // Matches bulkImportExcel.js's matrixToPayload() shape — every key
        // always present, null when blank.
        return array_merge([
            'name' => null,
            'phone_e164' => null,
            'email' => null,
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

    // ══ 1. Phone + valid email ══════════════════════════════════════════

    #[Test]
    public function csv_phone_and_valid_email_creates_a_contact_with_both(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        $this->actingAs($user)->post(route('client.contacts.import'), [
            'file' => $this->csvUpload(['Phone', 'Email'], [[self::PHONE, 'Honey@Example.com']]),
            'default_country' => 'IN',
        ])->assertRedirect();

        $contact = Contact::where('workspace_id', $workspace->id)->sole();
        $this->assertSame(self::PHONE, $contact->phone_e164);
        // Lowercased for matching — requirement 1.
        $this->assertSame('honey@example.com', $contact->email);
    }

    #[Test]
    public function grid_phone_and_valid_email_creates_a_contact_with_both(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        $this->actingAs($user)->post(route('client.contacts.bulk-store'), [
            'default_country' => 'IN',
            'rows' => [$this->gridRow(['phone_e164' => self::PHONE, 'email' => '  Honey@Example.com  '])],
        ])->assertOk();

        $contact = Contact::where('workspace_id', $workspace->id)->sole();
        $this->assertSame(self::PHONE, $contact->phone_e164);
        $this->assertSame('honey@example.com', $contact->email, 'Must be trimmed AND lowercased.');
    }

    // ══ 2. Phone-only — unchanged, still works ══════════════════════════

    #[Test]
    public function csv_phone_only_still_creates_a_contact_exactly_as_before(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        $this->actingAs($user)->post(route('client.contacts.import'), [
            'file' => $this->csvUpload(['Phone'], [[self::PHONE]]),
            'default_country' => 'IN',
        ])->assertRedirect();

        $contact = Contact::where('workspace_id', $workspace->id)->sole();
        $this->assertSame(self::PHONE, $contact->phone_e164);
        $this->assertNull($contact->email);
    }

    #[Test]
    public function grid_phone_only_still_creates_a_contact_exactly_as_before(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        $this->actingAs($user)->post(route('client.contacts.bulk-store'), [
            'default_country' => 'IN',
            'rows' => [$this->gridRow(['phone_e164' => self::PHONE])],
        ])->assertOk();

        $contact = Contact::where('workspace_id', $workspace->id)->sole();
        $this->assertSame(self::PHONE, $contact->phone_e164);
        $this->assertNull($contact->email);
    }

    // ══ 3. Email-only ════════════════════════════════════════════════════

    #[Test]
    public function csv_email_only_creates_a_new_contact_when_nothing_matches(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        $this->actingAs($user)->post(route('client.contacts.import'), [
            'file' => $this->csvUpload(['Email'], [['brandnew@example.com']]),
        ])->assertRedirect();

        $contact = Contact::where('workspace_id', $workspace->id)->sole();
        $this->assertNull($contact->phone_e164);
        $this->assertSame('brandnew@example.com', $contact->email);
    }

    #[Test]
    public function grid_email_only_creates_a_new_contact_when_nothing_matches(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        // ⚠️ Requirement gate this test proves: the grid REQUIRED a phone on
        // every row before this change (PhoneNumber::normalizeForImport()
        // was called unconditionally and rejects a blank phone outright).
        // An email-only grid row is now valid, for the first time.
        $this->actingAs($user)->post(route('client.contacts.bulk-store'), [
            'rows' => [$this->gridRow(['email' => 'brandnew@example.com'])],
        ])->assertOk();

        $contact = Contact::where('workspace_id', $workspace->id)->sole();
        $this->assertNull($contact->phone_e164);
        $this->assertSame('brandnew@example.com', $contact->email);
    }

    #[Test]
    public function csv_email_only_updates_the_one_existing_contact_that_matches(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $existing = Contact::create([
            'workspace_id' => $workspace->id,
            'phone_e164' => self::PHONE,
            'email' => 'honey@example.com',
        ]);

        $this->actingAs($user)->post(route('client.contacts.import'), [
            'file' => $this->csvUpload(['Email', 'City'], [['honey@example.com', 'Gurugram']]),
        ])->assertRedirect();

        $this->assertSame(1, Contact::where('workspace_id', $workspace->id)->count());
        $existing->refresh();
        $this->assertSame(self::PHONE, $existing->phone_e164, 'The phone this row never mentioned must survive.');
        $this->assertSame('Gurugram', $existing->city);
    }

    #[Test]
    public function csv_email_only_row_is_rejected_when_it_matches_more_than_one_existing_contact(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        // Two pre-existing contacts already share this email — a state the
        // import itself would now prevent going forward, but data can
        // already be like this from other sources (the dashboard form has no
        // such uniqueness constraint either).
        Contact::create(['workspace_id' => $workspace->id, 'phone_e164' => self::PHONE, 'email' => 'shared@example.com']);
        Contact::create(['workspace_id' => $workspace->id, 'phone_e164' => self::PHONE_2, 'email' => 'shared@example.com']);

        $this->actingAs($user)->post(route('client.contacts.import'), [
            'file' => $this->csvUpload(['Email', 'City'], [['shared@example.com', 'Delhi']]),
        ])->assertRedirect();

        $this->assertSame(2, Contact::where('workspace_id', $workspace->id)->count(), 'No new contact, no merge — still exactly the original two.');
        $errors = session('import_errors');
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('more than one existing contact', implode(' ', $errors));

        // Neither pre-existing row was silently updated with the City value.
        $this->assertDatabaseMissing('contacts', ['workspace_id' => $workspace->id, 'city' => 'Delhi']);
    }

    // ══ 4. Invalid email rejection ═══════════════════════════════════════

    /** @return array<string, array{0: string}> */
    public static function invalidEmailProvider(): array
    {
        return [
            'no @' => ['not-an-email'],
            'no domain' => ['someone@'],
            'no local part' => ['@example.com'],
            'spaces inside' => ['some one@example.com'],
            'double @' => ['a@@example.com'],
        ];
    }

    #[Test]
    #[DataProvider('invalidEmailProvider')]
    public function csv_invalid_email_rejects_the_row_with_a_visible_error(string $invalid): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        $this->actingAs($user)->post(route('client.contacts.import'), [
            'file' => $this->csvUpload(['Phone', 'Email'], [[self::PHONE, $invalid]]),
            'default_country' => 'IN',
        ])->assertRedirect();

        $this->assertSame(0, Contact::where('workspace_id', $workspace->id)->count(), 'No blank/partial contact — the whole row is rejected.');
        $errors = session('import_errors');
        $this->assertNotEmpty($errors, "'{$invalid}' must produce a visible row error.");
        $this->assertStringContainsString('not a valid email address', implode(' ', $errors));
    }

    #[Test]
    #[DataProvider('invalidEmailProvider')]
    public function grid_invalid_email_rejects_the_row_with_a_visible_error(string $invalid): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        $this->actingAs($user)->post(route('client.contacts.bulk-store'), [
            'default_country' => 'IN',
            'rows' => [$this->gridRow(['phone_e164' => self::PHONE, 'email' => $invalid])],
        ])->assertOk();

        $this->assertSame(0, Contact::where('workspace_id', $workspace->id)->count());
        $this->assertNotEmpty(session('import_errors'));
    }

    #[Test]
    public function an_email_only_row_with_an_invalid_email_is_rejected_not_created_blank(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        // No phone at all, and the only identity offered is invalid — must
        // be rejected, never silently fall through to a blank contact.
        $this->actingAs($user)->post(route('client.contacts.import'), [
            'file' => $this->csvUpload(['Email', 'City'], [['not-an-email', 'Mumbai']]),
        ])->assertRedirect();

        $this->assertSame(0, Contact::where('workspace_id', $workspace->id)->count());
    }

    // ══ 5. Blank Email preserves an existing email ══════════════════════

    #[Test]
    public function csv_blank_email_column_does_not_erase_an_existing_email(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $existing = Contact::create([
            'workspace_id' => $workspace->id,
            'phone_e164' => self::PHONE,
            'email' => 'honey@example.com',
        ]);

        $this->actingAs($user)->post(route('client.contacts.import'), [
            'file' => $this->csvUpload(['Phone', 'Email', 'City'], [[self::PHONE, '', 'Noida']]),
            'default_country' => 'IN',
        ])->assertRedirect();

        $existing->refresh();
        $this->assertSame('honey@example.com', $existing->email, 'A blank Email cell must not erase the existing address.');
        $this->assertSame('Noida', $existing->city);
    }

    #[Test]
    public function csv_omitted_email_column_does_not_erase_an_existing_email(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $existing = Contact::create([
            'workspace_id' => $workspace->id,
            'phone_e164' => self::PHONE,
            'email' => 'honey@example.com',
        ]);

        // No Email COLUMN in the file at all this time.
        $this->actingAs($user)->post(route('client.contacts.import'), [
            'file' => $this->csvUpload(['Phone', 'City'], [[self::PHONE, 'Delhi']]),
            'default_country' => 'IN',
        ])->assertRedirect();

        $existing->refresh();
        $this->assertSame('honey@example.com', $existing->email);
        $this->assertSame('Delhi', $existing->city);
    }

    #[Test]
    public function grid_null_email_does_not_erase_an_existing_email(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $existing = Contact::create([
            'workspace_id' => $workspace->id,
            'phone_e164' => self::PHONE,
            'email' => 'honey@example.com',
        ]);

        $this->actingAs($user)->post(route('client.contacts.bulk-store'), [
            'default_country' => 'IN',
            'rows' => [$this->gridRow(['phone_e164' => self::PHONE, 'city' => 'Delhi'])],
        ])->assertOk();

        $existing->refresh();
        $this->assertSame('honey@example.com', $existing->email);
        $this->assertSame('Delhi', $existing->city);
    }

    // ══ 6. Same email in a DIFFERENT workspace ═══════════════════════════

    #[Test]
    public function an_email_that_exists_in_another_workspace_never_matches_or_updates_it(): void
    {
        ['user' => $userA, 'workspace' => $workspaceA] = $this->createWorkspaceContext();
        ['workspace' => $workspaceB] = $this->createWorkspaceContext();

        $bContact = Contact::create([
            'workspace_id' => $workspaceB->id,
            'phone_e164' => self::PHONE_2,
            'email' => 'shared@example.com',
            'city' => 'Original City',
        ]);

        $this->actingAs($userA)->post(route('client.contacts.import'), [
            'file' => $this->csvUpload(['Email', 'City'], [['shared@example.com', 'Hijacked']]),
        ])->assertRedirect();

        // A's import created its OWN new contact — it must never find,
        // update, or in any way touch workspace B's row.
        $this->assertSame(1, Contact::where('workspace_id', $workspaceA->id)->count());
        $aContact = Contact::where('workspace_id', $workspaceA->id)->sole();
        $this->assertSame('shared@example.com', $aContact->email);
        $this->assertSame('Hijacked', $aContact->city);

        $bContact->refresh();
        $this->assertSame('Original City', $bContact->city, 'Workspace B\'s contact must be completely untouched.');
    }

    #[Test]
    public function a_phone_email_row_does_not_conflict_with_an_identical_email_in_another_workspace(): void
    {
        // Rule 6 read narrowly: workspace-scoping means an email "belonging
        // to a different contact" can ONLY mean a different contact IN THE
        // SAME WORKSPACE. An identical email sitting in a totally different
        // workspace must never trigger the identity-conflict rejection.
        ['user' => $userA, 'workspace' => $workspaceA] = $this->createWorkspaceContext();
        ['workspace' => $workspaceB] = $this->createWorkspaceContext();

        Contact::create(['workspace_id' => $workspaceB->id, 'email' => 'shared@example.com']);

        $this->actingAs($userA)->post(route('client.contacts.import'), [
            'file' => $this->csvUpload(['Phone', 'Email'], [[self::PHONE, 'shared@example.com']]),
            'default_country' => 'IN',
        ])->assertRedirect();

        $this->assertSame(0, Contact::where('workspace_id', $workspaceA->id)->onlyTrashed()->count());
        $errors = session('import_errors');
        $this->assertEmpty($errors, 'Must not be rejected — the conflicting email is in a different workspace entirely.');

        $aContact = Contact::where('workspace_id', $workspaceA->id)->sole();
        $this->assertSame(self::PHONE, $aContact->phone_e164);
        $this->assertSame('shared@example.com', $aContact->email);
    }

    // ══ 7. Phone/email identity conflict ═════════════════════════════════

    #[Test]
    public function csv_a_row_whose_email_belongs_to_a_different_phone_matched_contact_is_rejected(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        Contact::create(['workspace_id' => $workspace->id, 'phone_e164' => self::PHONE, 'email' => 'honey@example.com']);
        $other = Contact::create(['workspace_id' => $workspace->id, 'phone_e164' => self::PHONE_2, 'first_name' => 'Piyush']);

        // A row whose PHONE matches contact #2 ("Piyush") but whose EMAIL
        // belongs to contact #1 ("honey@example.com") — refusing to merge
        // them is the whole point of this rule.
        $this->actingAs($user)->post(route('client.contacts.import'), [
            'file' => $this->csvUpload(['Phone', 'Email'], [[self::PHONE_2, 'honey@example.com']]),
            'default_country' => 'IN',
        ])->assertRedirect();

        $this->assertSame(2, Contact::where('workspace_id', $workspace->id)->count(), 'Still exactly the original two — no merge, no third contact.');
        $errors = session('import_errors');
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('already belongs to a different contact', implode(' ', $errors));

        $other->refresh();
        $this->assertNull($other->email, 'The conflicting row must not have written the email onto contact #2 either.');
    }

    #[Test]
    public function grid_phone_email_conflict_is_rejected_the_same_way(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        Contact::create(['workspace_id' => $workspace->id, 'phone_e164' => self::PHONE, 'email' => 'honey@example.com']);

        $this->actingAs($user)->post(route('client.contacts.bulk-store'), [
            'default_country' => 'IN',
            'rows' => [$this->gridRow(['phone_e164' => self::PHONE_2, 'email' => 'honey@example.com'])],
        ])->assertOk();

        $this->assertSame(1, Contact::where('workspace_id', $workspace->id)->count());
        $this->assertNotEmpty(session('import_errors'));
    }

    #[Test]
    public function a_new_phone_whose_email_already_belongs_to_someone_else_is_also_a_conflict(): void
    {
        // The phone side resolves to NOTHING (brand new) but the email
        // belongs to an existing contact — still a conflict per rule 4: "if
        // that email belongs to a different contact ... reject", regardless
        // of whether the phone side is new or existing.
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        Contact::create(['workspace_id' => $workspace->id, 'email' => 'honey@example.com']);

        $this->actingAs($user)->post(route('client.contacts.import'), [
            'file' => $this->csvUpload(['Phone', 'Email'], [[self::PHONE, 'honey@example.com']]),
            'default_country' => 'IN',
        ])->assertRedirect();

        $this->assertSame(1, Contact::where('workspace_id', $workspace->id)->count(), 'No new contact created — rejected instead.');
    }

    // ══ 8/9. Email Opt-in stays independent of the Email value ══════════

    #[Test]
    public function supplying_an_email_never_implies_email_opt_in(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        $this->actingAs($user)->post(route('client.contacts.import'), [
            'file' => $this->csvUpload(['Phone', 'Email'], [[self::PHONE, 'honey@example.com']]),
            'default_country' => 'IN',
        ])->assertRedirect();

        $contact = Contact::where('workspace_id', $workspace->id)->sole();
        $this->assertSame('honey@example.com', $contact->email);
        $this->assertFalse($contact->opt_in_email, 'Having an email address must not turn Email Opt-in on by itself.');
    }

    #[Test]
    public function email_with_explicit_email_opt_in_no_stores_the_email_and_stays_opted_out(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        $this->actingAs($user)->post(route('client.contacts.import'), [
            'file' => $this->csvUpload(['Phone', 'Email', 'Email Opt-in'], [[self::PHONE, 'honey@example.com', 'No']]),
            'default_country' => 'IN',
        ])->assertRedirect();

        $contact = Contact::where('workspace_id', $workspace->id)->sole();
        $this->assertSame('honey@example.com', $contact->email);
        $this->assertFalse($contact->opt_in_email);
    }

    #[Test]
    public function email_with_explicit_email_opt_in_yes_stores_the_email_and_opts_in(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        $this->actingAs($user)->post(route('client.contacts.import'), [
            'file' => $this->csvUpload(['Phone', 'Email', 'Email Opt-in'], [[self::PHONE, 'honey@example.com', 'Yes']]),
            'default_country' => 'IN',
        ])->assertRedirect();

        $contact = Contact::where('workspace_id', $workspace->id)->sole();
        $this->assertSame('honey@example.com', $contact->email);
        $this->assertTrue($contact->opt_in_email);
    }

    #[Test]
    public function grid_email_with_opt_in_yes_and_a_different_row_with_opt_in_no_are_independent(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        $this->actingAs($user)->post(route('client.contacts.bulk-store'), [
            'default_country' => 'IN',
            'rows' => [
                $this->gridRow(['phone_e164' => self::PHONE, 'email' => 'honey@example.com', 'opt_in_email' => 'Yes']),
                $this->gridRow(['phone_e164' => self::PHONE_2, 'email' => 'piyush@example.com', 'opt_in_email' => 'No']),
            ],
        ])->assertOk();

        $honey = Contact::where('workspace_id', $workspace->id)->where('phone_e164', self::PHONE)->sole();
        $piyush = Contact::where('workspace_id', $workspace->id)->where('phone_e164', self::PHONE_2)->sole();
        $this->assertTrue($honey->opt_in_email);
        $this->assertFalse($piyush->opt_in_email);
    }

    // ══ 10. No outbound automation/message/webhook job ══════════════════

    #[Test]
    public function importing_contacts_with_emails_still_dispatches_no_contact_created_event(): void
    {
        Event::fake([ContactCreated::class]);
        Queue::fake();
        Bus::fake();

        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        $this->actingAs($user)->post(route('client.contacts.import'), [
            'file' => $this->csvUpload(['Phone', 'Email'], [[self::PHONE, 'honey@example.com']]),
            'default_country' => 'IN',
        ])->assertRedirect();

        $this->actingAs($user)->post(route('client.contacts.bulk-store'), [
            'rows' => [$this->gridRow(['email' => 'piyush@example.com'])],
        ])->assertOk();

        $this->assertSame(2, Contact::where('workspace_id', $workspace->id)->count());

        Event::assertNotDispatched(ContactCreated::class);
        Queue::assertNothingPushed();
        Bus::assertNothingDispatched();
    }

    #[Test]
    public function an_email_identified_import_fires_no_automation_run_and_no_outbound_webhook(): void
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

        // Email-only, opted in on every channel — exactly the kind of row
        // someone might assume should trigger a welcome send. It still must
        // not, during import.
        $this->actingAs($user)->post(route('client.contacts.bulk-store'), [
            'rows' => [$this->gridRow([
                'email' => 'honey@example.com',
                'opt_in_whatsapp' => 'Yes',
                'opt_in_sms' => 'Yes',
                'opt_in_email' => 'Yes',
            ])],
        ])->assertOk();

        $contact = Contact::where('workspace_id', $workspace->id)->sole();
        $this->assertTrue($contact->opt_in_email);

        Queue::assertNotPushed(ExecuteAutomationRunJob::class);
        Queue::assertNotPushed(DispatchWebhookJob::class);
        $this->assertSame(0, AutomationRun::count());
    }

    // ══ Sample files include distinct valid example emails ══════════════

    #[Test]
    public function the_csv_sample_headers_with_email_are_all_recognized_by_the_real_import_endpoint(): void
    {
        // Round-trip proof, same discipline as the phone-country sample test:
        // build the sample with the REAL, unmodified JS generator, then
        // actually POST it through the real endpoint.
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        $content = $this->buildSampleCsvViaNode(['code' => 'IN', 'name' => 'India', 'calling_code' => '+91', 'label' => 'India (+91)', 'example' => '9123456789']);
        $file = UploadedFile::fake()->createWithContent('sample.csv', $content);

        $response = $this->actingAs($user)->post(route('client.contacts.import'), [
            'file' => $file,
            'default_country' => 'IN',
        ]);
        $response->assertRedirect();

        $this->assertEmpty(session('import_errors'), 'The sample CSV must import cleanly: '.implode('; ', session('import_errors') ?? []));

        $created = Contact::where('workspace_id', $workspace->id)->get();
        $this->assertSame(2, $created->count());

        $emails = $created->pluck('email')->filter()->all();
        $this->assertCount(2, $emails, 'Both sample rows must supply a usable email.');
        $this->assertSame($emails, array_unique($emails), 'Sample emails must be distinct.');
        foreach ($emails as $email) {
            $this->assertNotFalse(filter_var($email, FILTER_VALIDATE_EMAIL));
        }
    }

    private function buildSampleCsvViaNode(?array $country): string
    {
        $source = file_get_contents(base_path('resources/js/Pages/Contacts/bulkImportExcel.js'));
        $this->assertNotFalse($source);

        $needle = "import i18n from '@/i18n';";
        $this->assertStringContainsString($needle, $source);
        $stubbed = str_replace($needle, 'const i18n = { t: (k) => k };', $source);

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

        $this->assertNotNull($output);
        $this->assertStringNotContainsString('Error', $output, "node reported an error:\n{$output}");

        return $output;
    }
}
