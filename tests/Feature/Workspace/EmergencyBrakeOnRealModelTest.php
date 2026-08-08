<?php

namespace Tests\Feature\Workspace;

use App\Modules\Shared\Models\Contact;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ⚠️ THE BRAKE, PULLED WITH THE CAR MOVING.
 *
 * `WorkspaceScopeFlagTest` proves `ENFORCE_WORKSPACE_SCOPE` works — but it
 * exercises `ScopedFixture`, a model declared inside the test suite. So it
 * proves the SCOPE CLASS reads the flag. It does not prove that pulling the
 * brake restores access to `Contact`, `Conversation` or `ChannelAccount` — the
 * models an actual incident would be about.
 *
 * The inference from one to the other is sound: the scope is shared, and it
 * reads config per query. But "sound inference, never executed" is exactly what
 * the Instagram echo fix was (it had never worked) and what the `Queue::before`
 * flush was (correct between jobs, wrong inside one). Both were reasoned about
 * and both were wrong.
 *
 * `Contact` is the subject because the difference is unmistakable: it is the
 * most widely bound model in the codebase, it soft-deletes, and a cross-workspace
 * read of it is the exact failure Phase 0 exists to prevent. If the brake works
 * anywhere, it must work here.
 */
class EmergencyBrakeOnRealModelTest extends TestCase
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

    private function seedContacts(): void
    {
        foreach ([[11, '+15550000011'], [22, '+15550000022'], [22, '+15550000023']] as [$ws, $phone]) {
            DB::table('contacts')->insert([
                'uuid' => (string) Str::uuid(),
                'workspace_id' => $ws,
                'phone_e164' => $phone,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    #[Test]
    public function with_the_brake_on_a_real_scoped_model_filters(): void
    {
        config(['workspace.enforce_scope' => true]);
        $this->seedContacts();

        $this->assertSame(3, DB::table('contacts')->count(), 'Positive control: three rows exist.');
        $this->assertSame(1, WorkspaceContext::for(11, fn () => Contact::count()));
        $this->assertSame(2, WorkspaceContext::for(22, fn () => Contact::count()));
        $this->assertSame(0, Contact::count(), 'Null context fails closed, as designed.');
    }

    /**
     * The one that matters. In an incident an operator sets
     * ENFORCE_WORKSPACE_SCOPE=false, clears the config cache, and needs the
     * application to work again — on the REAL models, not on a fixture.
     */
    #[Test]
    public function with_the_brake_off_a_real_scoped_model_returns_cross_workspace_rows(): void
    {
        config(['workspace.enforce_scope' => false]);
        $this->seedContacts();

        $this->assertSame(3, WorkspaceContext::for(11, fn () => Contact::count()),
            'The brake did not release Contact. An operator pulling it during an incident '
            .'would still be looking at a filtered application.');

        $this->assertSame(3, Contact::count(),
            'With the brake off, a NULL context must also stop failing closed — otherwise '
            .'pulling it converts "sees the wrong rows" into "sees no rows", which is not a '
            .'recovery.');
    }

    /**
     * ⚠️ THE FINDING. Pulling the brake does NOT open a cross-tenant hole.
     *
     * I expected 200 here and got 403 — and the 403 is the better answer.
     *
     * With the brake ON, route-model binding never resolves a foreign contact,
     * so the request 404s before authorization runs (§G-4).
     *
     * With the brake OFF, binding DOES resolve it — the scope is genuinely
     * released, which is the whole point — and the controller's own explicit
     * workspace check, the one added in Phase 1c, refuses it with a 403.
     *
     * So the two layers are independent, and that matters for the incident this
     * brake exists for: an operator can pull it to un-break the application
     * without opening cross-tenant access through the controllers. The
     * hand-written checks are what hold while the scope is off.
     *
     * It also answers a question recorded in docs/found-bugs.md — "are the 1c
     * controller checks redundant now that the scope exists?" They are not.
     * This is the demonstration.
     */
    #[Test]
    public function pulling_the_brake_releases_the_scope_without_opening_cross_tenant_access(): void
    {
        ['user' => $user, 'workspace' => $ws] = $this->createWorkspaceContext();

        $foreignUuid = (string) Str::uuid();
        DB::table('contacts')->insert([
            'uuid' => $foreignUuid,
            'workspace_id' => 99999,
            'phone_e164' => '+15550009999',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Brake ON: binding never resolves it. Denied at the routing layer.
        config(['workspace.enforce_scope' => true]);
        $this->actingAs($user)->get(route('client.contacts.show', $foreignUuid))->assertNotFound();

        // Brake OFF: binding resolves it — proving the scope really was released —
        // and the controller's own check refuses. Denied at the authorization layer.
        config(['workspace.enforce_scope' => false]);
        $this->actingAs($user)->get(route('client.contacts.show', $foreignUuid))->assertForbidden();
    }

    /**
     * POSITIVE CONTROL for the above. The 403 must mean "this contact is not
     * yours", not "this route refuses everyone with the brake off" — otherwise
     * the brake would be useless in the incident it exists for.
     */
    #[Test]
    public function with_the_brake_off_a_user_can_still_reach_their_own_records(): void
    {
        ['user' => $user, 'workspace' => $ws] = $this->createWorkspaceContext();

        $ownUuid = (string) Str::uuid();
        DB::table('contacts')->insert([
            'uuid' => $ownUuid,
            'workspace_id' => $ws->id,
            'phone_e164' => '+15550001111',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        config(['workspace.enforce_scope' => false]);

        $this->actingAs($user)->get(route('client.contacts.show', $ownUuid))->assertOk();
    }
}
