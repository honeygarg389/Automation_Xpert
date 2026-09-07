<?php

namespace Tests\Feature\Social;

use App\Models\User;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Models\SocialPost;
use App\Modules\Social\Services\SocialPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ═══ CROSS-WORKSPACE ACCOUNT ATTACHMENT VIA update() ════════════════════════
 *
 * `store()` guards target_accounts with an explicit ownership query, and so does
 * the v1 API controller. `update()` validated them only as `integer` and wrote
 * them straight to the post — the guard was simply absent from one of the three
 * write paths.
 *
 * ⚠️ SEVERITY IS LOWER THAN IT LOOKS, AND THE REASON MATTERS.
 * SocialPublisher re-scopes to the post's own workspace before publishing:
 *
 *     SocialAccount::where('workspace_id', $post->workspace_id)
 *         ->whereIn('id', $post->target_accounts ?? [])
 *
 * so a foreign id is silently dropped and NOTHING is ever posted to another
 * workspace's account. That second check is what turns this from "post to a
 * stranger's Instagram" into "write a stranger's account id into your own row".
 *
 * It is still worth closing: defence in depth (the publisher's scope is the only
 * thing standing between this and cross-tenant posting), the stored id is a
 * small cross-tenant information leak, and a post silently publishes to fewer
 * accounts than the author selected with no error shown.
 *
 * @see SocialPublisher::publish()
 */
class SocialPostUpdateWorkspaceScopeTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: SocialAccount, 2: SocialPost} */
    private function victimAndAttacker(): array
    {
        // Workspace B — the victim, with a connected account.
        ['workspace' => $victimWs] = $this->createWorkspaceContext();
        $victimAccount = SocialAccount::create([
            'workspace_id' => $victimWs->id,
            'network' => 'facebook',
            'account_id' => 'victim-fb',
            'name' => 'Victim Page',
            'access_token' => 'victim-token',
            'active' => true,
        ]);

        // Workspace A — the attacker, with their own post and account.
        ['workspace' => $attackerWs, 'user' => $attacker] = $this->createWorkspaceContext();
        $attackerAccount = SocialAccount::create([
            'workspace_id' => $attackerWs->id,
            'network' => 'facebook',
            'account_id' => 'attacker-fb',
            'name' => 'Attacker Page',
            'access_token' => 'attacker-token',
            'active' => true,
        ]);

        $post = SocialPost::create([
            'workspace_id' => $attackerWs->id,
            'body' => 'draft',
            'media_urls' => [],
            'target_accounts' => [$attackerAccount->id],
            'status' => 'draft',
        ]);

        $this->assertNotSame($victimWs->id, $attackerWs->id, 'the two workspaces must differ');

        return [$attacker, $victimAccount, $post];
    }

    /**
     * ⚠️ THE REGRESSION TEST. Before the fix this returned 302 with no errors and
     * persisted the victim's account id.
     */
    #[Test]
    public function update_rejects_an_account_from_another_workspace(): void
    {
        [$attacker, $victimAccount, $post] = $this->victimAndAttacker();
        $before = $post->target_accounts;

        $this->actingAs($attacker)
            ->from(route('client.social.posts.edit', $post->id))
            ->put(route('client.social.posts.update', $post->id), [
                'body' => 'updated',
                'target_accounts' => [$victimAccount->id],
            ])
            ->assertSessionHasErrors('target_accounts');

        $this->assertSame($before, $post->fresh()->target_accounts,
            "workspace B's account id was written into workspace A's post");
    }

    /** Mixing a legitimate account with a foreign one must fail as a whole. */
    #[Test]
    public function update_rejects_a_mix_of_own_and_foreign_accounts(): void
    {
        [$attacker, $victimAccount, $post] = $this->victimAndAttacker();
        $own = $post->target_accounts[0];

        $this->actingAs($attacker)
            ->from(route('client.social.posts.edit', $post->id))
            ->put(route('client.social.posts.update', $post->id), [
                'body' => 'updated',
                'target_accounts' => [$own, $victimAccount->id],
            ])
            ->assertSessionHasErrors('target_accounts');

        $this->assertNotContains($victimAccount->id, $post->fresh()->target_accounts);
    }

    /**
     * ⚠️ THE POSITIVE CONTROL. Every assertion above is "the request is refused";
     * if update() started refusing everything they would all pass while the
     * endpoint was broken.
     */
    #[Test]
    public function update_still_works_normally_with_the_users_own_accounts(): void
    {
        ['workspace' => $ws, 'user' => $user] = $this->createWorkspaceContext();

        $a = SocialAccount::create([
            'workspace_id' => $ws->id, 'network' => 'facebook', 'account_id' => 'fb-1',
            'name' => 'One', 'access_token' => 't', 'active' => true,
        ]);
        $b = SocialAccount::create([
            'workspace_id' => $ws->id, 'network' => 'instagram', 'account_id' => 'ig-1',
            'name' => 'Two', 'access_token' => 't', 'active' => true,
        ]);

        $post = SocialPost::create([
            'workspace_id' => $ws->id, 'body' => 'draft', 'media_urls' => [],
            'target_accounts' => [$a->id], 'status' => 'draft',
        ]);

        $this->actingAs($user)
            ->put(route('client.social.posts.update', $post->id), [
                'body' => 'updated body',
                'target_accounts' => [$a->id, $b->id],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('client.social.posts.index'));

        $fresh = $post->fresh();
        $this->assertSame('updated body', $fresh->body);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $fresh->target_accounts);
    }

    /** The guard must not have been weakened on the path that already had one. */
    #[Test]
    public function store_still_rejects_a_foreign_account(): void
    {
        [$attacker, $victimAccount] = $this->victimAndAttacker();

        $this->actingAs($attacker)
            ->from(route('client.social.composer'))
            ->post(route('client.social.posts.store'), [
                'body' => 'new post',
                'target_accounts' => [$victimAccount->id],
            ])
            ->assertSessionHasErrors('target_accounts');
    }
}
