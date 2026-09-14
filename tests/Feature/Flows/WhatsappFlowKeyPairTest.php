<?php

namespace Tests\Feature\Flows;

use App\Modules\Flows\Models\WhatsappFlowKeyPair;
use App\Modules\Flows\Services\WhatsappFlowKeyPairService;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use App\Modules\Whatsapp\Models\WhatsappPhoneNumber;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WhatsappFlowKeyPairTest extends TestCase
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

    /** @return array{user:\App\Models\User,workspace:\App\Models\Workspace,phone:WhatsappPhoneNumber} */
    private function connectedPhone(): array
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $waba = WhatsappBusinessAccount::factory()->create([
            'workspace_id' => $workspace->id,
            'waba_id' => 'waba-keys-'.$workspace->id,
            'credentials' => ['system_user_token' => 'meta-test-token'],
            'status' => 'active',
        ]);
        $phone = WhatsappPhoneNumber::create([
            'waba_id_fk' => $waba->id,
            'phone_number_id' => 'phone-keys-'.$workspace->id,
            'display_phone' => '+1555000'.$workspace->id,
        ]);

        return compact('user', 'workspace', 'phone');
    }

    #[Test]
    public function generate_creates_a_workspace_scoped_key_pair_with_an_encrypted_private_key_and_incrementing_version(): void
    {
        ['user' => $user, 'workspace' => $workspace, 'phone' => $phone] = $this->connectedPhone();

        $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('client.flows.keys.generate', $phone->id))
            ->assertRedirect();

        $first = WhatsappFlowKeyPair::query()->firstOrFail();
        $raw = (string) DB::table('whatsapp_flow_key_pairs')->where('id', $first->id)->value('private_key_pem');
        $this->assertSame($workspace->id, $first->workspace_id);
        $this->assertSame($phone->id, $first->whatsapp_phone_number_id);
        $this->assertSame(1, $first->key_version);
        $this->assertSame(WhatsappFlowKeyPair::UPLOAD_NOT_UPLOADED, $first->meta_upload_status);
        $this->assertStringContainsString('BEGIN', $first->private_key_pem);
        $this->assertStringNotContainsString('BEGIN RSA PRIVATE KEY', $raw);
        $this->assertStringNotContainsString('BEGIN PRIVATE KEY', $raw);

        $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('client.flows.keys.generate', $phone->id));
        $this->assertSame(2, WhatsappFlowKeyPair::query()->max('key_version'));
    }

    #[Test]
    public function rotate_uploads_a_new_version_and_marks_the_prior_active_key_rotated(): void
    {
        ['user' => $user, 'workspace' => $workspace, 'phone' => $phone] = $this->connectedPhone();
        $old = WorkspaceContext::for($workspace->id, fn () => app(WhatsappFlowKeyPairService::class)->generate($phone, $workspace->id));
        Http::fake([
            "https://graph.facebook.com/v20.0/{$phone->phone_number_id}/whatsapp_business_encryption" => Http::response(['success' => true]),
        ]);

        $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('client.flows.keys.rotate', $phone->id))
            ->assertRedirect()
            ->assertSessionHas('success', 'Encryption key rotated and uploaded to Meta.');

        $old = $old->fresh();
        $new = WhatsappFlowKeyPair::query()->where('key_version', 2)->firstOrFail();
        $this->assertSame(WhatsappFlowKeyPair::STATUS_ROTATED, $old->status);
        $this->assertNotNull($old->rotated_at);
        $this->assertSame(WhatsappFlowKeyPair::STATUS_ACTIVE, $new->status);
        $this->assertSame(WhatsappFlowKeyPair::UPLOAD_UPLOADED, $new->meta_upload_status);
        Http::assertSent(fn (HttpRequest $request) => $request->method() === 'POST'
            && $request->url() === "https://graph.facebook.com/v20.0/{$phone->phone_number_id}/whatsapp_business_encryption"
            && str_contains($request->body(), 'business_public_key'));
    }

    #[Test]
    public function missing_messaging_permission_is_stored_as_an_actionable_upload_failure(): void
    {
        ['user' => $user, 'workspace' => $workspace, 'phone' => $phone] = $this->connectedPhone();
        $key = WorkspaceContext::for($workspace->id, fn () => app(WhatsappFlowKeyPairService::class)->generate($phone, $workspace->id));
        Http::fake([
            "https://graph.facebook.com/v20.0/{$phone->phone_number_id}/whatsapp_business_encryption" => Http::response([
                'error' => ['message' => 'Application does not have permission for this action', 'code' => 200],
            ], 403),
        ]);

        $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('client.flows.keys.upload', $phone->id))
            ->assertRedirect()
            ->assertSessionHas('error', WhatsappFlowKeyPairService::MISSING_MESSAGING_PERMISSION_MESSAGE);

        $this->assertSame(WhatsappFlowKeyPair::UPLOAD_FAILED, $key->fresh()->meta_upload_status);
        $this->assertSame(WhatsappFlowKeyPairService::MISSING_MESSAGING_PERMISSION_MESSAGE, $key->fresh()->meta_upload_error);
    }

    #[Test]
    public function another_workspace_cannot_generate_or_view_a_phone_numbers_key_pair(): void
    {
        ['workspace' => $workspace, 'phone' => $phone] = $this->connectedPhone();
        ['user' => $otherUser, 'workspace' => $otherWorkspace] = $this->createWorkspaceContext();

        $this->actingAs($otherUser)->withSession(['current_workspace_id' => $otherWorkspace->id])
            ->post(route('client.flows.keys.generate', $phone->id))
            ->assertNotFound();

        $this->actingAs($otherUser)->withSession(['current_workspace_id' => $otherWorkspace->id])
            ->get(route('client.flows.keys.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('phones', []));
        $this->assertDatabaseMissing('whatsapp_flow_key_pairs', ['workspace_id' => $workspace->id]);
    }
}
