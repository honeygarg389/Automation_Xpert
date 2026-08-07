<?php

namespace Tests\Feature\Workspace\Modules;

use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Segment;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 1c — Shared module (14 resolution sites across 2 controllers, 19 public
 * methods). The largest single-module count so far, and the one other modules
 * lean on: Contact is the customer record that Inbox, Broadcasting, Ecommerce
 * and Automation all route-bind to.
 *
 * Two of the 14 are §G-1b AUTHORIZATION guards covering 10 of the 19 methods:
 *
 *   ContactController::authoriseContact()  -> show, update, destroy,
 *                                             uploadAvatar, deleteAvatar
 *   SegmentController::authorise()          -> update, destroy, manageContacts,
 *                                             attachContacts, detachContact
 *
 * Every "blocked" assertion is paired with a positive control on the SAME route
 * and verb, per CLAUDE.md.
 *
 * Traps guarded, each one having bitten a previous group:
 *  - MIXED ROUTE KEYS. Contact binds by `uuid`, Segment binds by `id`. Using
 *    the wrong one 404s at binding and the guard is never reached, so the test
 *    would prove nothing. They are NOT the same here — checked per model rather
 *    than assumed from the module.
 *  - SOFT DELETES. Contact DOES soft-delete, so assertDatabaseHas cannot prove
 *    a delete was prevented — a soft-deleted row still matches. The destroy
 *    tests use assertNotSoftDeleted/assertSoftDeleted. Segment does not
 *    soft-delete, so assertDatabaseMissing is correct there.
 *  - ONE REQUEST PER TEST. WorkspaceContext memoises per user id for the life
 *    of a test, so switched and unswitched cases are separate tests.
 */
class SharedWorkspaceScopingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        WorkspaceContext::flush();
        Queue::fake();
        Http::fake();
    }

    protected function tearDown(): void
    {
        WorkspaceContext::flush();
        parent::tearDown();
    }

    private function contact(int $workspaceId, string $first): Contact
    {
        return Contact::create([
            'workspace_id' => $workspaceId,
            'first_name' => $first,
            'phone_e164' => '+1555'.random_int(1000000, 9999999),
        ]);
    }

    private function segment(int $workspaceId, string $name): Segment
    {
        return Segment::create([
            'workspace_id' => $workspaceId,
            'name' => $name,
            'type' => 'static',
        ]);
    }

    // ── Contact list follows the switch ─────────────────────────────────────

    #[Test]
    public function the_contact_list_shows_the_home_workspace_when_unswitched(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $this->contact($home->id, 'HomeContact');
        $this->contact($other->id, 'OtherContact');

        $this->actingAs($user)
            ->get(route('client.contacts.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('contacts.data', 1)
                ->where('contacts.data.0.first_name', 'HomeContact'));
    }

    #[Test]
    public function the_contact_list_follows_the_switched_workspace(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $this->contact($home->id, 'HomeContact');
        $this->contact($other->id, 'OtherContact');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->get(route('client.contacts.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('contacts.data', 1)
                ->where('contacts.data.0.first_name', 'OtherContact'));
    }

    #[Test]
    public function a_new_contact_is_created_in_the_switched_workspace(): void
    {
        ['user' => $user, 'other' => $other] = $this->createTwoWorkspaceUser();

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->post(route('client.contacts.store'), [
                'first_name' => 'SwitchedContact',
                'phone_e164' => '+15551230001',
            ]);

        $this->assertDatabaseHas('contacts', [
            'first_name' => 'SwitchedContact',
            'workspace_id' => $other->id,
        ]);
    }

    // ── §G-1b: ContactController::authoriseContact() ────────────────────────

    #[Test]
    public function viewing_another_tenants_contact_is_blocked(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $homeContact = $this->contact($home->id, 'HomeContact');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->get(route('client.contacts.show', $homeContact->uuid))
            ->assertForbidden();
    }

    #[Test]
    public function viewing_a_contact_in_the_current_workspace_succeeds(): void
    {
        ['user' => $user, 'home' => $home] = $this->createTwoWorkspaceUser();
        $homeContact = $this->contact($home->id, 'HomeContact');

        $this->actingAs($user)
            ->get(route('client.contacts.show', $homeContact->uuid))
            ->assertOk();
    }

    /**
     * Contact SOFT-DELETES, so assertDatabaseHas would pass even if the delete
     * had gone through — a trashed row still matches. assertNotSoftDeleted is
     * the assertion that can actually fail here.
     */
    #[Test]
    public function deleting_another_tenants_contact_is_blocked(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $homeContact = $this->contact($home->id, 'HomeContact');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->delete(route('client.contacts.destroy', $homeContact->uuid))
            ->assertForbidden();

        $this->assertNotSoftDeleted($homeContact);
    }

    #[Test]
    public function deleting_a_contact_in_the_current_workspace_succeeds(): void
    {
        ['user' => $user, 'home' => $home] = $this->createTwoWorkspaceUser();
        $homeContact = $this->contact($home->id, 'HomeContact');

        $this->actingAs($user)
            ->delete(route('client.contacts.destroy', $homeContact->uuid))
            ->assertRedirect();

        $this->assertSoftDeleted($homeContact);
    }

    #[Test]
    public function updating_another_tenants_contact_is_blocked(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $homeContact = $this->contact($home->id, 'HomeContact');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->put(route('client.contacts.update', $homeContact->uuid), ['first_name' => 'Hijacked'])
            ->assertForbidden();

        $this->assertDatabaseHas('contacts', ['id' => $homeContact->id, 'first_name' => 'HomeContact']);
    }

    #[Test]
    public function updating_a_contact_in_the_current_workspace_succeeds(): void
    {
        ['user' => $user, 'home' => $home] = $this->createTwoWorkspaceUser();
        $homeContact = $this->contact($home->id, 'HomeContact');

        $this->actingAs($user)
            ->put(route('client.contacts.update', $homeContact->uuid), ['first_name' => 'Renamed'])
            ->assertRedirect();

        $this->assertDatabaseHas('contacts', ['id' => $homeContact->id, 'first_name' => 'Renamed']);
    }

    #[Test]
    public function deleting_another_tenants_contact_avatar_is_blocked(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $homeContact = $this->contact($home->id, 'HomeContact');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->delete(route('client.contacts.avatar.delete', $homeContact->uuid))
            ->assertForbidden();
    }

    #[Test]
    public function deleting_a_contact_avatar_in_the_current_workspace_is_authorized(): void
    {
        ['user' => $user, 'home' => $home] = $this->createTwoWorkspaceUser();
        $homeContact = $this->contact($home->id, 'HomeContact');

        $this->actingAs($user)
            ->delete(route('client.contacts.avatar.delete', $homeContact->uuid))
            ->assertRedirect();
    }

    // ── Bulk paths scope to the resolved workspace ──────────────────────────

    #[Test]
    public function bulk_delete_cannot_reach_another_tenants_contacts(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $homeContact = $this->contact($home->id, 'HomeContact');

        // bulkDestroy scopes by workspace rather than 403ing, so the proof is
        // that the foreign contact survives. The payload key MUST be `uuids`:
        // an `ids` payload fails validation, nothing is deleted, and this test
        // passes for the wrong reason. Its positive control below is what
        // exposed that — it failed while this one still passed.
        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->delete(route('client.contacts.bulk-destroy'), ['uuids' => [$homeContact->uuid]]);

        $this->assertNotSoftDeleted($homeContact);
    }

    #[Test]
    public function bulk_delete_removes_contacts_in_the_current_workspace(): void
    {
        ['user' => $user, 'home' => $home] = $this->createTwoWorkspaceUser();
        $homeContact = $this->contact($home->id, 'HomeContact');

        $this->actingAs($user)
            ->delete(route('client.contacts.bulk-destroy'), ['uuids' => [$homeContact->uuid]]);

        $this->assertSoftDeleted($homeContact);
    }

    #[Test]
    public function the_contact_export_contains_only_the_switched_workspace(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $this->contact($home->id, 'HomeContact');
        $this->contact($other->id, 'OtherContact');

        $body = $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->get(route('client.contacts.export'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('OtherContact', $body);
        $this->assertStringNotContainsString('HomeContact', $body, 'The export leaked the home workspace.');
    }

    // ── Segments ────────────────────────────────────────────────────────────

    #[Test]
    public function the_segment_list_shows_the_home_workspace_when_unswitched(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $this->segment($home->id, 'HomeSegment');
        $this->segment($other->id, 'OtherSegment');

        $this->actingAs($user)
            ->get(route('client.segments.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('segments', 1)
                ->where('segments.0.name', 'HomeSegment'));
    }

    #[Test]
    public function the_segment_list_follows_the_switched_workspace(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $this->segment($home->id, 'HomeSegment');
        $this->segment($other->id, 'OtherSegment');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->get(route('client.segments.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('segments', 1)
                ->where('segments.0.name', 'OtherSegment'));
    }

    #[Test]
    public function a_new_segment_is_created_in_the_switched_workspace(): void
    {
        ['user' => $user, 'other' => $other] = $this->createTwoWorkspaceUser();

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->post(route('client.segments.store'), ['name' => 'SwitchedSegment', 'type' => 'static']);

        $this->assertDatabaseHas('segments', [
            'name' => 'SwitchedSegment',
            'workspace_id' => $other->id,
        ]);
    }

    // ── §G-1b: SegmentController::authorise() — Segment binds by ID, not uuid ─

    #[Test]
    public function deleting_another_tenants_segment_is_blocked(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $homeSegment = $this->segment($home->id, 'HomeSegment');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->delete(route('client.segments.destroy', $homeSegment->id))
            ->assertForbidden();

        // Segment does NOT soft-delete, so a surviving row is a real proof.
        $this->assertDatabaseHas('segments', ['id' => $homeSegment->id]);
    }

    #[Test]
    public function deleting_a_segment_in_the_current_workspace_succeeds(): void
    {
        ['user' => $user, 'home' => $home] = $this->createTwoWorkspaceUser();
        $homeSegment = $this->segment($home->id, 'HomeSegment');

        $this->actingAs($user)
            ->delete(route('client.segments.destroy', $homeSegment->id))
            ->assertRedirect();

        $this->assertDatabaseMissing('segments', ['id' => $homeSegment->id]);
    }

    #[Test]
    public function managing_another_tenants_segment_contacts_is_blocked(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $homeSegment = $this->segment($home->id, 'HomeSegment');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->get(route('client.segments.contacts', $homeSegment->id))
            ->assertForbidden();
    }

    #[Test]
    public function managing_segment_contacts_in_the_current_workspace_succeeds(): void
    {
        ['user' => $user, 'home' => $home] = $this->createTwoWorkspaceUser();
        $homeSegment = $this->segment($home->id, 'HomeSegment');

        $this->actingAs($user)
            ->get(route('client.segments.contacts', $homeSegment->id))
            ->assertOk();
    }
}
