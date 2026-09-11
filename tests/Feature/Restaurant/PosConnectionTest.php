<?php

namespace Tests\Feature\Restaurant;

use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Workspace;
use App\Modules\Restaurant\Models\PosConnection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Task 4 — PosConnection::verifyToken() and the workspace()-relation-without-
 * scope pattern.
 */
class PosConnectionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function pos_connection_does_not_use_the_belongs_to_workspace_trait(): void
    {
        $this->assertNotContains(BelongsToWorkspace::class, class_uses_recursive(PosConnection::class),
            'PosConnection must remain resolvable by an unauthenticated webhook — no automatic scope.');
    }

    #[Test]
    public function verify_token_matches_the_correct_plaintext_token(): void
    {
        $token = 'super-secret-webhook-token';
        $connection = PosConnection::factory()->create();
        $connection->forceFill(['webhook_secret_hash' => hash('sha256', $token)])->save();

        $this->assertTrue(PosConnection::verifyToken($connection, $token));
    }

    #[Test]
    public function verify_token_rejects_the_wrong_plaintext_token(): void
    {
        $connection = PosConnection::factory()->create();
        $connection->forceFill(['webhook_secret_hash' => hash('sha256', 'correct-token')])->save();

        $this->assertFalse(PosConnection::verifyToken($connection, 'wrong-token'));
    }

    #[Test]
    public function verify_token_never_matches_when_no_hash_is_stored_even_against_an_empty_string(): void
    {
        $connection = PosConnection::factory()->create();
        $this->assertNull($connection->webhook_secret_hash);

        $this->assertFalse(PosConnection::verifyToken($connection, ''));
        $this->assertFalse(PosConnection::verifyToken($connection, 'anything'));
    }

    #[Test]
    public function find_active_by_provider_and_ref_only_matches_connected_status(): void
    {
        PosConnection::factory()->create([
            'provider' => PosConnection::PROVIDER_PETPOOJA,
            'external_ref' => 'rest-1',
            'status' => PosConnection::STATUS_PENDING,
        ]);

        $this->assertNull(PosConnection::findActiveByProviderAndRef(PosConnection::PROVIDER_PETPOOJA, 'rest-1'));

        $connected = PosConnection::factory()->create([
            'provider' => PosConnection::PROVIDER_PETPOOJA,
            'external_ref' => 'rest-2',
            'status' => PosConnection::STATUS_CONNECTED,
        ]);

        $found = PosConnection::findActiveByProviderAndRef(PosConnection::PROVIDER_PETPOOJA, 'rest-2');
        $this->assertNotNull($found);
        $this->assertTrue($found->is($connected));
    }

    /**
     * ⚠️ Proves the workspace() relation works CORRECTLY while the model
     * carries NO automatic scope: a cross-tenant query must succeed (nothing
     * filters it), and the relation must still resolve the right Workspace
     * row once a connection is in hand.
     */
    #[Test]
    public function workspace_relation_resolves_correctly_with_no_automatic_scope_applied(): void
    {
        ['workspace' => $workspaceA] = $this->createWorkspaceContext();
        ['workspace' => $workspaceB] = $this->createWorkspaceContext();

        PosConnection::factory()->create(['workspace_id' => $workspaceA->id, 'external_ref' => 'a-1']);
        PosConnection::factory()->create(['workspace_id' => $workspaceB->id, 'external_ref' => 'b-1']);

        // A plain, unauthenticated-style query across both tenants succeeds —
        // there is no ambient workspace context here at all, and nothing 1=0s it.
        $all = PosConnection::query()->get();
        $this->assertCount(2, $all, 'A cross-tenant query must not be silently filtered.');

        $connA = $all->firstWhere('external_ref', 'a-1');
        $connB = $all->firstWhere('external_ref', 'b-1');

        $this->assertInstanceOf(BelongsTo::class, $connA->workspace());
        $this->assertInstanceOf(Workspace::class, $connA->workspace()->first());
        $this->assertSame($workspaceA->id, $connA->workspace()->first()->id);
        $this->assertSame($workspaceB->id, $connB->workspace()->first()->id);
    }

    #[Test]
    public function credentials_and_webhook_secret_hash_are_hidden_from_serialization(): void
    {
        $connection = PosConnection::factory()->create(['credentials' => ['api_key' => 'shh']]);
        $connection->forceFill(['webhook_secret_hash' => hash('sha256', 'x')])->save();

        $array = $connection->fresh()->toArray();

        $this->assertArrayNotHasKey('credentials', $array);
        $this->assertArrayNotHasKey('webhook_secret_hash', $array);
    }
}
