<?php

namespace Tests\Feature\MarketingSuite;

use App\Models\User;
use App\Models\Workspace;
use App\Modules\AI\Models\AiChatbot;
use App\Modules\Shared\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Verifies that one workspace cannot access another workspace's resources.
 */
class MultiTenantScopingTest extends TestCase
{
    use RefreshDatabase;

    private function createUserWithWorkspace(): array
    {
        $user = User::factory()->create(['role' => 'client', 'email_verified_at' => now()]);
        $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
        $user->update(['workspace_id' => $workspace->id]);
        $user->refresh();

        return [$user, $workspace];
    }

    #[Test]
    public function workspace_a_cannot_see_workspace_b_contacts(): void
    {
        [$userA, $workspaceA] = $this->createUserWithWorkspace();
        [$userB, $workspaceB] = $this->createUserWithWorkspace();

        Contact::factory()->create([
            'workspace_id' => $workspaceB->id,
            'first_name' => 'WorkspaceB',
            'last_name' => 'UniqueContactName',
            'phone_e164' => '+8801999999999',
        ]);

        // Compute the actual Inertia asset version from the Vite manifest
        $inertiaVersion = file_exists(public_path('build/manifest.json'))
            ? hash_file('xxh128', public_path('build/manifest.json'))
            : '';

        $response = $this->actingAs($userA)
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $inertiaVersion])
            ->get('/app/contacts');

        $response->assertStatus(200);
        $response->assertJsonMissing(['first_name' => 'WorkspaceB']);
    }

    #[Test]
    public function workspace_a_cannot_delete_workspace_b_contact(): void
    {
        [$userA, $workspaceA] = $this->createUserWithWorkspace();
        [$userB, $workspaceB] = $this->createUserWithWorkspace();

        $contactB = Contact::factory()->create([
            'workspace_id' => $workspaceB->id,
            'first_name' => 'B',
            'last_name' => 'Contact',
            'phone_e164' => '+8801888888888',
        ]);

        // Contact::getRouteKeyName() is 'uuid'. Passing the integer id made this
        // 404 at route-model binding, so the test never reached the
        // authorization check it was written to verify.
        $response = $this->actingAs($userA)->delete("/app/contacts/{$contactB->uuid}");
        $response->assertStatus(403);

        // Contact soft-deletes, so assertDatabaseHas alone is not load-bearing:
        // the row survives a *successful* delete too. Assert deleted_at is still
        // null, which is what actually proves the contact was not removed.
        $this->assertNotSoftDeleted('contacts', ['id' => $contactB->id]);
    }

    /**
     * Positive control for workspace_a_cannot_delete_workspace_b_contact.
     *
     * Without this, a 403 on the cross-workspace attempt proves nothing — it
     * could mean the endpoint rejects everyone. This asserts the same user, the
     * same route and the same verb succeed against their OWN contact, so the
     * 403 above is demonstrably about workspace ownership.
     */
    #[Test]
    public function workspace_owner_can_delete_their_own_contact(): void
    {
        [$userA, $workspaceA] = $this->createUserWithWorkspace();

        $contactA = Contact::factory()->create([
            'workspace_id' => $workspaceA->id,
            'first_name' => 'A',
            'last_name' => 'OwnContact',
            'phone_e164' => '+8801777777777',
        ]);

        $this->actingAs($userA)
            ->delete("/app/contacts/{$contactA->uuid}")
            ->assertStatus(302);

        // Contact uses SoftDeletes, so the row persists with deleted_at set —
        // assertDatabaseMissing would wrongly fail on a successful delete.
        $this->assertSoftDeleted('contacts', ['id' => $contactA->id]);
    }

    #[Test]
    public function workspace_a_cannot_edit_workspace_b_chatbot(): void
    {
        [$userA, $workspaceA] = $this->createUserWithWorkspace();
        [$userB, $workspaceB] = $this->createUserWithWorkspace();

        $chatbot = AiChatbot::create([
            'workspace_id' => $workspaceB->id,
            'name' => 'B Chatbot',
        ]);

        $response = $this->actingAs($userA)->put("/app/ai/chatbots/{$chatbot->uuid}", ['name' => 'Hacked']);
        $response->assertStatus(403);
        $this->assertDatabaseHas('ai_chatbots', ['id' => $chatbot->id, 'name' => 'B Chatbot']);
    }
}
