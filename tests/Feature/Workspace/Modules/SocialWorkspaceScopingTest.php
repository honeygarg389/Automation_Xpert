<?php

namespace Tests\Feature\Workspace\Modules;

use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Models\SocialPost;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 1c — Social module (2 resolution sites, 5 §G-1b authorization sites).
 *
 * SocialPostController::workspaceId() and SocialAccountController::workspaceId()
 * are identical private helpers, but between them they feed FIVE abort_unless()
 * checks: SocialPostController::update/publishNow/cancel (each compares
 * $post->workspace_id) and SocialAccountController::disconnect (compares
 * $account->workspace_id). Before this commit every one of those checks
 * compared against the user's HOME workspace regardless of the switch.
 *
 * No SocialPost/SocialAccount factory exists yet; built inline against the
 * required-column set confirmed against the schema.
 */
class SocialWorkspaceScopingTest extends TestCase
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

    private function makePost(int $workspaceId, string $title, string $status = 'draft'): SocialPost
    {
        return SocialPost::create([
            'workspace_id' => $workspaceId,
            'title' => $title,
            'status' => $status,
            'ai_generated' => false,
        ]);
    }

    /**
     * update() requires body + target_accounts (min:1); title is nullable.
     *
     * ⚠️ THE ACCOUNT ID MUST BE REAL AND IN THE POST'S WORKSPACE. This used to
     * hardcode `[1]` — an account that no test ever creates — and passed only
     * because update() had no ownership guard on target_accounts. Once that
     * guard was added (matching the one store() always had), the two positive
     * controls below failed: they had been asserting "an update succeeds" while
     * submitting an account that does not exist.
     *
     * The 403 cases can still pass the default: they abort on the post's own
     * workspace check before validation runs, so the account is never examined.
     */
    private function validUpdatePayload(?int $accountId = null): array
    {
        return ['body' => 'Updated body', 'target_accounts' => [$accountId ?? 1]];
    }

    private function makeAccount(int $workspaceId, string $name): SocialAccount
    {
        return SocialAccount::create([
            'workspace_id' => $workspaceId,
            'network' => 'facebook',
            'account_id' => 'acct_'.$name,
            'name' => $name,
            'access_token' => 'test-token',
            'active' => true,
        ]);
    }

    // ── Listing follows the switched workspace ───────────────────────────────

    #[Test]
    public function the_post_list_follows_the_switched_workspace(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $this->makePost($home->id, 'HomePost');
        $this->makePost($other->id, 'OtherPost');

        $body = $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->get(route('client.social.posts.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('OtherPost', $body);
        $this->assertStringNotContainsString('HomePost', $body);
    }

    // ── §G-1b: SocialPostController — 3 of its 4 abort_unless sites ──────────

    #[Test]
    public function updating_another_tenants_post_is_blocked(): void
    {
        ['user' => $user] = $this->createTwoWorkspaceUser();
        ['home' => $foreign] = $this->createTwoWorkspaceUser(['email' => 'foreign-social-1@example.com']);
        $post = $this->makePost($foreign->id, 'Foreign');

        $this->actingAs($user)
            ->put(route('client.social.posts.update', $post), $this->validUpdatePayload())
            ->assertForbidden();

        $this->assertDatabaseHas('social_media_posts', ['id' => $post->id, 'body' => null]);
    }

    /** POSITIVE CONTROL for the above. */
    #[Test]
    public function updating_a_post_in_the_home_workspace_succeeds(): void
    {
        ['user' => $user, 'home' => $home] = $this->createTwoWorkspaceUser();
        $post = $this->makePost($home->id, 'Mine');
        $account = $this->makeAccount($home->id, 'HomeAcct');

        $this->actingAs($user)
            ->put(route('client.social.posts.update', $post), $this->validUpdatePayload($account->id))
            // ⚠️ assertRedirect() alone cannot tell success from a validation
            // failure — both redirect. The no-errors assertion is what makes
            // this a positive control rather than a shape check.
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('social_media_posts', ['id' => $post->id, 'body' => 'Updated body']);
    }

    /** §G-1b: after switching, a post in the switched-into workspace is editable. */
    #[Test]
    public function updating_a_post_in_the_switched_workspace_succeeds(): void
    {
        ['user' => $user, 'other' => $other] = $this->createTwoWorkspaceUser();
        $post = $this->makePost($other->id, 'InOther');
        $account = $this->makeAccount($other->id, 'OtherAcct');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->put(route('client.social.posts.update', $post), $this->validUpdatePayload($account->id))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('social_media_posts', ['id' => $post->id, 'body' => 'Updated body']);
    }

    /** The converse: after switching, the home-workspace post is out of scope. */
    #[Test]
    public function after_switching_a_home_workspace_post_cannot_be_updated(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $post = $this->makePost($home->id, 'HomePost');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->put(route('client.social.posts.update', $post), $this->validUpdatePayload())
            ->assertForbidden();

        $this->assertDatabaseHas('social_media_posts', ['id' => $post->id, 'body' => null]);
    }

    #[Test]
    public function cancelling_another_tenants_post_is_blocked(): void
    {
        ['user' => $user] = $this->createTwoWorkspaceUser();
        ['home' => $foreign] = $this->createTwoWorkspaceUser(['email' => 'foreign-social-2@example.com']);
        $post = $this->makePost($foreign->id, 'Foreign', 'scheduled');

        $this->actingAs($user)
            ->post(route('client.social.posts.cancel', $post))
            ->assertForbidden();
    }

    /** POSITIVE CONTROL. */
    #[Test]
    public function cancelling_a_post_in_the_home_workspace_succeeds(): void
    {
        ['user' => $user, 'home' => $home] = $this->createTwoWorkspaceUser();
        $post = $this->makePost($home->id, 'Mine', 'scheduled');

        $this->actingAs($user)
            ->post(route('client.social.posts.cancel', $post))
            ->assertRedirect();
    }

    #[Test]
    public function publish_now_on_another_tenants_post_is_blocked(): void
    {
        ['user' => $user] = $this->createTwoWorkspaceUser();
        ['home' => $foreign] = $this->createTwoWorkspaceUser(['email' => 'foreign-social-3@example.com']);
        $post = $this->makePost($foreign->id, 'Foreign', 'draft');

        $this->actingAs($user)
            ->post(route('client.social.posts.publish-now', $post))
            ->assertForbidden();
    }

    /** POSITIVE CONTROL. */
    #[Test]
    public function publish_now_on_a_post_in_the_home_workspace_is_authorized(): void
    {
        ['user' => $user, 'home' => $home] = $this->createTwoWorkspaceUser();
        $post = $this->makePost($home->id, 'Mine', 'draft');

        $response = $this->actingAs($user)->post(route('client.social.posts.publish-now', $post));

        // Publishing may fail downstream (no connected account) — the point
        // here is only that authorization passes, i.e. it is not a 403.
        $this->assertNotSame(403, $response->getStatusCode());
    }

    // ── §G-1b: SocialAccountController::disconnect ───────────────────────────

    #[Test]
    public function disconnecting_another_tenants_account_is_blocked(): void
    {
        ['user' => $user] = $this->createTwoWorkspaceUser();
        ['home' => $foreign] = $this->createTwoWorkspaceUser(['email' => 'foreign-social-4@example.com']);
        $account = $this->makeAccount($foreign->id, 'ForeignAccount');

        $this->actingAs($user)
            ->delete(route('client.social.accounts.disconnect', $account))
            ->assertForbidden();

        $this->assertDatabaseHas('social_media_accounts', ['id' => $account->id]);
    }

    /** POSITIVE CONTROL. */
    #[Test]
    public function disconnecting_an_account_in_the_home_workspace_succeeds(): void
    {
        ['user' => $user, 'home' => $home] = $this->createTwoWorkspaceUser();
        $account = $this->makeAccount($home->id, 'MyAccount');

        $this->actingAs($user)
            ->delete(route('client.social.accounts.disconnect', $account))
            ->assertRedirect();
    }

    /** §G-1b: after switching, an account in the switched-into workspace can be disconnected. */
    #[Test]
    public function disconnecting_an_account_in_the_switched_workspace_succeeds(): void
    {
        ['user' => $user, 'other' => $other] = $this->createTwoWorkspaceUser();
        $account = $this->makeAccount($other->id, 'InOther');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->delete(route('client.social.accounts.disconnect', $account))
            ->assertRedirect();
    }
}
