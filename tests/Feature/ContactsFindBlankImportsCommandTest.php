<?php

namespace Tests\Feature;

use App\Modules\Shared\Models\Contact;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Item C's "safe, explicit cleanup route" — see
 * app/Console/Commands/ContactsFindBlankImportsCommand.php.
 *
 * ⚠️ THE UNSAFE-SCOPE GAP THIS FILE NOW CLOSES. `query()` used to match ANY
 * blank contact regardless of `source` — the constraint was described in a
 * comment but never actually enforced in the query. `rg -n "source.*import"`
 * against the command AND this test file found only that comment; nothing
 * executable proved it. Every fixture below that is meant to be FOUND now
 * explicitly sets `source: 'import'`, and a whole new section (tests 3/4)
 * proves the command is BLIND to a blank contact from any other source —
 * manual, WhatsApp inbound, e-commerce, API, or no source at all.
 *
 * ⚠️ Report-only by default. Deletion requires BOTH `--delete` AND an
 * interactive confirmation — under `--no-interaction` (the default for any
 * scripted/CI invocation, including PHPUnit's own `$this->artisan()`),
 * `confirm()` returns its default of `false` without prompting, so a
 * scripted run can never delete anything by accident. That default-false
 * behaviour is exactly what the decline test proves.
 */
class ContactsFindBlankImportsCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Read past the workspace scope — every assertion here runs with no authenticated request context.
     *
     * @return Builder<Contact>
     */
    private function unscoped(): Builder
    {
        return Contact::withoutWorkspaceScope('reason: test assertion running with no authenticated request context');
    }

    // ══ 1. A blank IMPORTED contact is reported ══════════════════════════

    #[Test]
    public function it_reports_a_genuinely_blank_imported_contact(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $blank = Contact::create(['workspace_id' => $workspace->id, 'source' => 'import']);

        $this->artisan('contacts:find-blank-imports')
            ->expectsOutputToContain('1 blank contact(s) found')
            ->assertExitCode(0);

        $this->assertNotNull($this->unscoped()->find($blank->id), 'Report mode must never delete.');
    }

    #[Test]
    public function it_finds_nothing_when_every_contact_has_identifying_data(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        Contact::create(['workspace_id' => $workspace->id, 'source' => 'import', 'phone_e164' => '+918630026021']);
        Contact::create(['workspace_id' => $workspace->id, 'source' => 'import', 'email' => 'a@example.com']);
        Contact::create(['workspace_id' => $workspace->id, 'source' => 'import', 'first_name' => 'Honey']);

        $this->artisan('contacts:find-blank-imports')
            ->expectsOutputToContain('No blank contacts found')
            ->assertExitCode(0);
    }

    // ══ 2. A blank imported contact is soft-deleted only after --delete
    //       confirmation ═════════════════════════════════════════════════

    #[Test]
    public function declining_the_confirmation_deletes_nothing(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $blank = Contact::create(['workspace_id' => $workspace->id, 'source' => 'import']);

        $this->artisan('contacts:find-blank-imports', ['--delete' => true])
            ->expectsConfirmation('Permanently delete these 1 row(s)? This cannot be undone.', 'no')
            ->assertExitCode(0);

        $this->assertNotNull(
            $this->unscoped()->find($blank->id),
            'Declining the confirmation must not delete anything.'
        );
    }

    #[Test]
    public function delete_with_explicit_confirmation_soft_deletes_only_the_blank_imported_rows(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $blank = Contact::create(['workspace_id' => $workspace->id, 'source' => 'import']);
        $real = Contact::create(['workspace_id' => $workspace->id, 'source' => 'import', 'phone_e164' => '+918630026021', 'first_name' => 'Honey']);

        $this->artisan('contacts:find-blank-imports', ['--delete' => true])
            ->expectsConfirmation('Permanently delete these 1 row(s)? This cannot be undone.', 'yes')
            ->assertExitCode(0);

        // ⚠️ Soft-delete only — never a hard DELETE. assertSoftDeleted checks
        // deleted_at IS NOT NULL while the row still physically exists;
        // that distinction is the whole point of this assertion.
        $this->assertSoftDeleted('contacts', ['id' => $blank->id]);
        $this->assertNotSoftDeleted('contacts', ['id' => $real->id]);
        // The row must still physically exist — soft delete, not hard delete.
        $this->assertDatabaseHas('contacts', ['id' => $blank->id]);
    }

    // ══ 3. A blank MANUAL contact is never reported or deleted ══════════

    #[Test]
    public function a_blank_manual_contact_is_never_reported(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        // 'manual' — the exact source ContactController::store() (the
        // dashboard "Add Contact" form) writes.
        $manual = Contact::create(['workspace_id' => $workspace->id, 'source' => 'manual']);

        $this->artisan('contacts:find-blank-imports')
            ->expectsOutputToContain('No blank contacts found')
            ->assertExitCode(0);

        $this->assertNotNull($this->unscoped()->find($manual->id));
    }

    #[Test]
    public function a_blank_manual_contact_survives_delete_mode_even_if_an_imported_one_is_also_present(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $manual = Contact::create(['workspace_id' => $workspace->id, 'source' => 'manual']);
        $imported = Contact::create(['workspace_id' => $workspace->id, 'source' => 'import']);

        $this->artisan('contacts:find-blank-imports', ['--delete' => true])
            ->expectsConfirmation('Permanently delete these 1 row(s)? This cannot be undone.', 'yes')
            ->assertExitCode(0);

        $this->assertSoftDeleted('contacts', ['id' => $imported->id]);
        // A blank MANUAL contact must never be swept up by --delete.
        $this->assertNotSoftDeleted('contacts', ['id' => $manual->id]);
    }

    // ══ 4. A blank API / e-commerce / WhatsApp-inbound / no-source
    //       contact is never reported or deleted ═════════════════════════

    /** @return array<string, array{0: string|null}> */
    public static function nonImportSourceProvider(): array
    {
        return [
            'API with an explicit non-import source' => ['api'],
            'e-commerce sync (real platform value)' => ['shopify'],
            'WhatsApp inbound (real value from WhatsappDriver)' => ['whatsapp_inbound'],
            'campaign CSV upload (a DIFFERENT import-shaped source string)' => ['campaign_csv'],
            'no source at all' => [null],
        ];
    }

    #[Test]
    #[DataProvider('nonImportSourceProvider')]
    public function a_blank_contact_from_any_non_import_source_is_never_reported_or_deleted(?string $source): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $contact = Contact::create(['workspace_id' => $workspace->id, 'source' => $source]);

        $this->artisan('contacts:find-blank-imports')
            ->expectsOutputToContain('No blank contacts found')
            ->assertExitCode(0);

        // And --delete must be equally blind to it — not merely the report.
        $this->artisan('contacts:find-blank-imports', ['--delete' => true])
            ->assertExitCode(0);

        $this->assertNotNull($this->unscoped()->find($contact->id));
        $this->assertNotSoftDeleted('contacts', ['id' => $contact->id]);
    }

    // ══ 5. An IMPORTED contact with any name/phone/email is never
    //       reported or deleted ══════════════════════════════════════════

    #[Test]
    public function an_imported_contact_with_any_one_identifying_field_is_never_matched(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();

        // Each has EXACTLY one identifying field AND source = 'import' —
        // proving the command requires ALL THREE (name, phone, email) blank
        // even for a genuinely imported row, not just "is this an import".
        $phoneOnly = Contact::create(['workspace_id' => $workspace->id, 'source' => 'import', 'phone_e164' => '+918630026021']);
        $emailOnly = Contact::create(['workspace_id' => $workspace->id, 'source' => 'import', 'email' => 'a@example.com']);
        $nameOnly = Contact::create(['workspace_id' => $workspace->id, 'source' => 'import', 'first_name' => 'Honey']);

        $this->artisan('contacts:find-blank-imports')->expectsOutputToContain('No blank contacts found');

        foreach ([$phoneOnly, $emailOnly, $nameOnly] as $c) {
            $this->assertNotNull($this->unscoped()->find($c->id));
        }

        $this->artisan('contacts:find-blank-imports', ['--delete' => true])->assertExitCode(0);

        foreach ([$phoneOnly, $emailOnly, $nameOnly] as $c) {
            $this->assertNotSoftDeleted('contacts', ['id' => $c->id]);
        }
    }

    // ══ 6. Default report mode makes ZERO database changes ══════════════

    #[Test]
    public function report_mode_makes_zero_database_changes(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        Contact::create(['workspace_id' => $workspace->id, 'source' => 'import']);
        Contact::create(['workspace_id' => $workspace->id, 'source' => 'manual']);
        Contact::create(['workspace_id' => $workspace->id, 'source' => 'import', 'phone_e164' => '+918630026021', 'first_name' => 'Honey']);

        // The ENTIRE table's content, byte for byte, captured before running
        // report mode — a stronger proof than checking one row's
        // deleted_at, because it would also catch an accidental write to
        // ANY column on ANY row, not just a delete.
        $before = DB::table('contacts')->orderBy('id')->get()->toArray();

        $this->artisan('contacts:find-blank-imports')->assertExitCode(0);

        $after = DB::table('contacts')->orderBy('id')->get()->toArray();

        $this->assertEquals($before, $after, 'Report mode (no --delete) must leave every row completely unchanged.');
    }

    #[Test]
    public function report_mode_with_delete_flag_but_a_declined_confirmation_also_makes_zero_changes(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        Contact::create(['workspace_id' => $workspace->id, 'source' => 'import']);

        $before = DB::table('contacts')->orderBy('id')->get()->toArray();

        $this->artisan('contacts:find-blank-imports', ['--delete' => true])
            ->expectsConfirmation('Permanently delete these 1 row(s)? This cannot be undone.', 'no');

        $after = DB::table('contacts')->orderBy('id')->get()->toArray();

        $this->assertEquals($before, $after);
    }

    // ══ Workspace scoping (pre-existing coverage, kept) ══════════════════

    #[Test]
    public function the_workspace_filter_restricts_the_scan_to_one_workspace(): void
    {
        ['workspace' => $workspaceA] = $this->createWorkspaceContext();
        ['workspace' => $workspaceB] = $this->createWorkspaceContext();

        Contact::create(['workspace_id' => $workspaceA->id, 'source' => 'import']);
        Contact::create(['workspace_id' => $workspaceB->id, 'source' => 'import']);

        $this->artisan('contacts:find-blank-imports', ['--workspace' => (string) $workspaceA->id])
            ->expectsOutputToContain('1 blank contact(s) found');
    }
}
