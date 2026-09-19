<?php

namespace Tests\Feature\Flows;

use App\Models\Plan;
use App\Modules\Flows\Models\WhatsappFlow;
use App\Modules\Flows\Services\WhatsappFlowJsonCompiler;
use App\Modules\Flows\Services\WhatsappFlowMetaSyncService;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use App\Modules\Whatsapp\Models\WhatsappPhoneNumber;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Sections A-M — the imported-flow -> duplicate -> compiler round-trip fix.
 * Meta JSON fixture -> importer (decompile) -> local flow -> duplicate ->
 * compiler, exercising every piece of the reported bug in one realistic
 * scenario rather than three isolated toy cases:
 *
 *   - "customer_name"     already valid       -> preserved unchanged
 *   - "customer name"     invalid (space),
 *                         sanitizes to the
 *                         SAME base as #1     -> disambiguated "customer_name_a"
 *   - "reference-code"    invalid (hyphen)    -> sanitized to "reference_code"
 *   - "promo_code_1"      character-class
 *                         valid, but the
 *                         "field_1" shape     -> stripped to "promo_code"
 *
 * plus the empty screen.data -> {} fix (this fixture's only non-terminal
 * screen has no PRIOR screen, so its compiled `data` is always the
 * empty-object case), plus flow-level meta_passthrough (routing_model,
 * data_api_version) surviving both decompile() and a subsequent Duplicate.
 */
class WhatsappFlowImportedRoundTripTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        WorkspaceContext::flush();
    }

    protected function tearDown(): void
    {
        WorkspaceContext::flush();
        parent::tearDown();
    }

    /** A realistic, VALID Meta Flow JSON — not produced by compile(), representing a real FLOW_JSON asset. */
    private function importedMetaFlowFixture(): array
    {
        return [
            'version' => '6.3',
            'routing_model' => ['CONTACT_US' => ['SUCCESS']],
            'data_api_version' => '3.0',
            'screens' => [
                [
                    'id' => 'CONTACT_US',
                    'title' => 'Contact Us',
                    'terminal' => false,
                    'layout' => [
                        'type' => 'SingleColumnLayout',
                        'children' => [[
                            'type' => 'Form',
                            'name' => 'contact_us_form',
                            'children' => [
                                ['type' => 'TextHeading', 'text' => 'Tell us about yourself'],
                                ['type' => 'TextInput', 'name' => 'customer_name', 'label' => 'Your name', 'input-type' => 'text', 'required' => true],
                                ['type' => 'TextInput', 'name' => 'customer name', 'label' => 'Your name again', 'input-type' => 'text', 'required' => false],
                                ['type' => 'TextInput', 'name' => 'reference-code', 'label' => 'Reference code', 'input-type' => 'text', 'required' => false],
                                ['type' => 'TextInput', 'name' => 'promo_code_1', 'label' => 'Promo code', 'input-type' => 'text', 'required' => false],
                                [
                                    'type' => 'Footer', 'label' => 'Send',
                                    'on-click-action' => [
                                        'name' => 'navigate',
                                        'next' => ['type' => 'screen', 'name' => 'SUCCESS'],
                                        'payload' => ['customer_name' => '${form.customer_name}'],
                                    ],
                                ],
                            ],
                        ]],
                    ],
                ],
                [
                    'id' => 'SUCCESS',
                    'title' => 'Success',
                    'terminal' => true,
                    'success' => true,
                    'layout' => [
                        'type' => 'SingleColumnLayout',
                        'children' => [[
                            'type' => 'Form',
                            'name' => 'success_form',
                            'children' => [
                                ['type' => 'TextHeading', 'text' => 'Thanks for reaching out.'],
                                ['type' => 'Footer', 'label' => 'Done', 'on-click-action' => ['name' => 'complete', 'payload' => []]],
                            ],
                        ]],
                    ],
                ],
            ],
        ];
    }

    #[Test]
    public function importing_then_duplicating_the_fixture_round_trips_identifiers_and_data_shape_correctly(): void
    {
        ['user' => $user, 'workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();
        $this->attachPlanToClient($client, Plan::factory()->create(['whatsapp_flows_enabled' => true]));
        $waba = WhatsappBusinessAccount::factory()->create([
            'workspace_id' => $workspace->id, 'waba_id' => 'waba-rt', 'status' => 'active',
            'credentials' => ['system_user_token' => 'token'],
        ]);
        WhatsappPhoneNumber::create(['waba_id_fk' => $waba->id, 'phone_number_id' => 'phone-rt']);

        $metaJson = $this->importedMetaFlowFixture();
        Http::fake([
            'https://graph.facebook.com/v20.0/meta-import-rt?*' => Http::response(['id' => 'meta-import-rt', 'name' => 'Contact form', 'status' => 'DRAFT', 'categories' => ['CONTACT_US']]),
            'https://graph.facebook.com/v20.0/meta-import-rt/assets' => Http::response(['data' => [
                ['asset_type' => 'FLOW_JSON', 'name' => 'flow.json', 'download_url' => 'https://assets.test/meta-import-rt.json'],
            ]]),
            'https://assets.test/meta-import-rt.json' => Http::response($metaJson),
        ]);

        // --- Step 1: import ---
        $imported = WorkspaceContext::for(
            $workspace->id,
            fn () => app(WhatsappFlowMetaSyncService::class)->importFlow($workspace->id, 'meta-import-rt')
        );

        $fieldsByLabel = collect($imported->screens[0]['fields'])->filter(fn (array $f) => $f['type'] !== 'heading')->keyBy('label');

        // Valid ID preserved unchanged.
        $this->assertSame('customer_name', $fieldsByLabel['Your name']['name']);
        // Sanitizes to the SAME base as an already-claimed name -> alphabetic disambiguation, never numeric.
        $this->assertSame('customer_name_a', $fieldsByLabel['Your name again']['name']);
        // Invalid characters sanitized deterministically.
        $this->assertSame('reference_code', $fieldsByLabel['Reference code']['name']);
        // Character-class-valid but the "field_1" numeric-suffix shape is still eliminated.
        $this->assertSame('promo_code', $fieldsByLabel['Promo code']['name']);
        foreach ($fieldsByLabel as $field) {
            $this->assertDoesNotMatchRegularExpression('/_[0-9]+$/', $field['name'], 'No imported/normalized field name may end in a bare numeric suffix.');
        }

        // Flow-level Meta data-exchange metadata captured, not dropped.
        $this->assertSame(['routing_model' => ['CONTACT_US' => ['SUCCESS']], 'data_api_version' => '3.0'], $imported->meta_passthrough);

        // --- Step 2: compile the IMPORTED flow and check the JSON shape directly ---
        $compiledImported = app(WhatsappFlowJsonCompiler::class)->compile($imported);
        $importedJson = json_encode($compiledImported, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('field_1', $importedJson);
        $this->assertStringContainsString('"data":{}', $importedJson, 'The only non-terminal screen has no prior screen, so its compiled data must be an empty OBJECT.');
        $this->assertStringNotContainsString('"data":[]', $importedJson);
        $this->assertSame('3.0', $compiledImported['data_api_version']);
        $this->assertSame(['CONTACT_US' => ['SUCCESS']], $compiledImported['routing_model']);

        // --- Step 3: duplicate it (through the real HTTP route, exercising Section E's wiring too) ---
        $this->actingAs($user)->post(route('client.flows.duplicate', $imported->uuid))->assertRedirect();
        $copy = WhatsappFlow::withoutWorkspaceScope('reason: test assertion')->where('name', 'Contact form (Copy)')->firstOrFail();

        // Section H/E — duplicate contract: no Meta connection at all, original untouched.
        $this->assertNull($copy->meta_flow_id);
        $this->assertNull($copy->meta_sync_status);
        $this->assertSame(WhatsappFlow::STATUS_DRAFT, $copy->status);
        $this->assertNotSame($imported->id, $copy->id);
        $imported = $imported->fresh();
        $this->assertSame('meta-import-rt', $imported->meta_flow_id, 'Duplicating must never mutate the original.');

        // Schema-preservation metadata IS copied (needed for a valid recompile), unlike the Meta connection itself.
        $this->assertSame($imported->meta_passthrough, $copy->meta_passthrough);
        $this->assertEquals($imported->screens, $copy->screens);

        // --- Step 4: compile the DUPLICATE and confirm it is exactly as clean as the original ---
        $compiledCopy = app(WhatsappFlowJsonCompiler::class)->compile($copy);
        $copyJson = json_encode($compiledCopy, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('field_1', $copyJson);
        $this->assertStringContainsString('"data":{}', $copyJson);
        $this->assertStringNotContainsString('"data":[]', $copyJson);
        $this->assertSame('3.0', $compiledCopy['data_api_version'], 'meta_passthrough must survive into the duplicate\'s own recompile.');

        // --- Step 5: local pre-sync validation passes cleanly (this is what syncToMeta() runs before ever calling Meta). ---
        $result = app(WhatsappFlowMetaSyncService::class)->syncToMeta($copy);
        // No WABA/phone configured on a fresh call context for $copy's workspace check isn't the point here —
        // what matters is that compiling never throws InvalidArgumentException for a malformed identifier or JSON shape.
        $this->assertIsArray($result);
    }

    /** Section H, regression (1): imported field -> duplicate -> compile must NOT generate field_1 or any invalid identifier. */
    #[Test]
    public function regression_imported_field_survives_duplicate_and_compile_without_ever_becoming_field_1(): void
    {
        ['user' => $user, 'workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();
        $this->attachPlanToClient($client, Plan::factory()->create(['whatsapp_flows_enabled' => true]));
        $waba = WhatsappBusinessAccount::factory()->create([
            'workspace_id' => $workspace->id, 'waba_id' => 'waba-reg1', 'status' => 'active',
            'credentials' => ['system_user_token' => 'token'],
        ]);
        WhatsappPhoneNumber::create(['waba_id_fk' => $waba->id, 'phone_number_id' => 'phone-reg1']);
        // getFlow succeeds, but the content fetch (assets) fails — the row is
        // left holding placeholderScreens(), the exact site the "field_1"
        // literal used to live.
        Http::fake([
            'https://graph.facebook.com/v20.0/meta-reg1?*' => Http::response(['id' => 'meta-reg1', 'name' => 'Reg1', 'status' => 'DRAFT', 'categories' => ['OTHER']]),
            'https://graph.facebook.com/v20.0/meta-reg1/assets' => Http::response(['error' => ['message' => 'boom']], 500),
        ]);

        $imported = WorkspaceContext::for($workspace->id, fn () => app(WhatsappFlowMetaSyncService::class)->importFlow($workspace->id, 'meta-reg1'));
        $this->assertNotSame('field_1', $imported->screens[0]['fields'][0]['name'], 'The failed-pull placeholder must not carry the old numeric-suffixed literal.');

        $this->actingAs($user)->post(route('client.flows.duplicate', $imported->uuid))->assertRedirect();
        $copy = WhatsappFlow::withoutWorkspaceScope('reason: test assertion')->where('name', 'Reg1 (Copy)')->firstOrFail();

        $compiled = app(WhatsappFlowJsonCompiler::class)->compile($copy);
        $json = json_encode($compiled, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('field_1', $json);
        $this->assertDoesNotMatchRegularExpression('/"id":"[a-zA-Z_]+_[0-9]+"/', $json, 'No identifier anywhere in the compiled duplicate may end in a bare numeric suffix.');
    }

    /** Section H, regression (2): imported screen with no data -> duplicate -> compile must generate "data": {} not "data": []. */
    #[Test]
    public function regression_imported_screen_with_no_prior_data_compiles_to_an_empty_object_not_an_empty_array(): void
    {
        ['user' => $user, 'workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();
        $this->attachPlanToClient($client, Plan::factory()->create(['whatsapp_flows_enabled' => true]));
        $waba = WhatsappBusinessAccount::factory()->create([
            'workspace_id' => $workspace->id, 'waba_id' => 'waba-reg2', 'status' => 'active',
            'credentials' => ['system_user_token' => 'token'],
        ]);
        WhatsappPhoneNumber::create(['waba_id_fk' => $waba->id, 'phone_number_id' => 'phone-reg2']);
        $metaJson = $this->importedMetaFlowFixture();
        Http::fake([
            'https://graph.facebook.com/v20.0/meta-reg2?*' => Http::response(['id' => 'meta-reg2', 'name' => 'Reg2', 'status' => 'DRAFT', 'categories' => ['OTHER']]),
            'https://graph.facebook.com/v20.0/meta-reg2/assets' => Http::response(['data' => [
                ['asset_type' => 'FLOW_JSON', 'name' => 'flow.json', 'download_url' => 'https://assets.test/meta-reg2.json'],
            ]]),
            'https://assets.test/meta-reg2.json' => Http::response($metaJson),
        ]);

        $imported = WorkspaceContext::for($workspace->id, fn () => app(WhatsappFlowMetaSyncService::class)->importFlow($workspace->id, 'meta-reg2'));
        $this->actingAs($user)->post(route('client.flows.duplicate', $imported->uuid))->assertRedirect();
        $copy = WhatsappFlow::withoutWorkspaceScope('reason: test assertion')->where('name', 'Reg2 (Copy)')->firstOrFail();

        $compiled = app(WhatsappFlowJsonCompiler::class)->compile($copy);
        $this->assertInstanceOf(\stdClass::class, $compiled['screens'][0]['data'], 'PHP must not encode this as [] — it must be a genuine empty-object value.');
        $json = json_encode($compiled, JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('"data":{}', $json);
        $this->assertStringNotContainsString('"data":[]', $json);
    }
}
