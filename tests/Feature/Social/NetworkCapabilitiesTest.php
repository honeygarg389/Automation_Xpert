<?php

namespace Tests\Feature\Social;

use App\Models\User;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Models\SocialPost;
use App\Modules\Social\Support\NetworkCapabilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ═══ ONE DEFINITION OF WHAT EACH NETWORK ACCEPTS ════════════════════════════
 *
 * The character limit was written out FOUR times — once in PHP for the AI
 * prompt, three times in JSX. Nothing failed when they disagreed, because
 * nothing compared them: the UI counted against one number while the platform
 * enforced another.
 *
 * ⚠️ Several assertions below pin values that are easy to get "nearly right".
 * Instagram's image ratio is a RANGE (4:5 to 1.91:1) and is commonly quoted as
 * "1:1 and 4:5" — the lower bound plus one interior value, silently dropping
 * landscape. A picker built on that would reject images Instagram accepts, and
 * no test that only checked "0.8 is allowed" would notice.
 */
class NetworkCapabilitiesTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function instagram_image_ratio_is_the_full_range_not_the_two_commonly_quoted_values(): void
    {
        $ig = NetworkCapabilities::for('instagram');

        $this->assertSame(0.8, $ig['image_ratio_min'], '4:5 is the LOWER bound');
        $this->assertSame(1.91, $ig['image_ratio_max'],
            'Instagram accepts up to 1.91:1 landscape. A max of 1.0 would mean the '
            .'"1:1 and 4:5" assumption was encoded instead of the documented range.');

        // A 16:9-ish landscape image sits inside the real range and outside the
        // commonly-assumed one.
        $this->assertGreaterThan(1.0, $ig['image_ratio_max']);
    }

    #[Test]
    public function reels_accept_a_wide_ratio_range_not_only_9_by_16(): void
    {
        $ig = NetworkCapabilities::for('instagram');

        // 9:16 is RECOMMENDED, not required — 0.5625 must be inside the range,
        // not equal to its bounds.
        $this->assertSame(0.01, $ig['video_ratio_min']);
        $this->assertSame(10.0, $ig['video_ratio_max']);
        $this->assertGreaterThan($ig['video_ratio_min'], 0.5625);
        $this->assertLessThan($ig['video_ratio_max'], 0.5625);
    }

    /** Facebook's video range is genuinely bounded, unlike Instagram's. */
    #[Test]
    public function facebook_video_ratio_is_bounded_to_9_16_through_16_9(): void
    {
        $fb = NetworkCapabilities::for('facebook');

        $this->assertSame(0.5625, $fb['video_ratio_min']);  // 9:16
        $this->assertSame(1.7778, $fb['video_ratio_max']);  // 16:9
    }

    /** ⚠️ Carousel caps differ far more than "everything caps at 10". */
    #[Test]
    public function carousel_limits_are_per_network(): void
    {
        $this->assertSame(10, NetworkCapabilities::for('instagram')['carousel_max']);
        $this->assertSame(20, NetworkCapabilities::for('linkedin')['carousel_max'],
            'LinkedIn MultiImage takes 20; assuming Instagram\'s 10 halves it');
        $this->assertSame(20, NetworkCapabilities::for('threads')['carousel_max']);
        $this->assertFalse(NetworkCapabilities::for('youtube')['supports_carousel']);
    }

    /** YouTube is video-only — the fact the composer could not previously know. */
    #[Test]
    public function youtube_does_not_support_images(): void
    {
        $this->assertFalse(NetworkCapabilities::supports('youtube', 'supports_image'));
        $this->assertTrue(NetworkCapabilities::supports('youtube', 'supports_video'));
    }

    /**
     * ⚠️ Pinterest's false is UNRESOLVED, not "no carousel". This test exists so
     * the distinction survives — if someone later reads the false as settled and
     * deletes the explanation, the comment this asserts on goes with it.
     */
    #[Test]
    public function pinterest_carousel_is_recorded_as_unresolved(): void
    {
        $this->assertFalse(NetworkCapabilities::for('pinterest')['supports_carousel']);

        $source = file_get_contents(app_path('Modules/Social/Support/NetworkCapabilities.php'));
        $this->assertStringContainsString('UNRESOLVED', $source,
            'The reason Pinterest carousel is false must stay documented — false '
            .'alone reads as "Pinterest has no carousel", which is not what the research found.');
    }

    /** Planned networks must not reach the UI — they cannot even be connected. */
    #[Test]
    public function only_driver_backed_networks_are_exposed_to_the_frontend(): void
    {
        $exposed = array_keys(NetworkCapabilities::forFrontend());

        $this->assertSame(['facebook', 'instagram', 'linkedin', 'twitter', 'youtube'], $exposed);
        $this->assertNotContains('pinterest', $exposed);
        $this->assertNotContains('threads', $exposed);
        $this->assertNotContains('tiktok', $exposed, 'TikTok was removed');
    }

    #[Test]
    public function min_char_limit_takes_the_strictest_network(): void
    {
        $this->assertSame(280, NetworkCapabilities::minCharLimit(['facebook', 'twitter']));
        $this->assertSame(2200, NetworkCapabilities::minCharLimit(['facebook', 'instagram']));
        $this->assertSame(5000, NetworkCapabilities::minCharLimit([]), 'preserves the old ?? 5000 default');
        $this->assertSame(5000, NetworkCapabilities::charLimit('a-network-we-do-not-know'));
    }

    // ── The consolidation itself ──────────────────────────────────────────

    /**
     * ⚠️ THE TEST THAT PROVES THERE IS ONE SOURCE, NOT FOUR.
     *
     * A test asserting "a 300-character post to X is rejected" would have passed
     * against any of the four old maps, since they all held 280. This overrides
     * the value in NetworkCapabilities at runtime and requires validation to
     * follow it — which it can only do if it actually reads from there.
     */
    #[Test]
    public function char_limit_validation_reads_from_the_single_source(): void
    {
        [$user, $account] = $this->clientWithAccount('twitter');

        // 300 chars: over X's real 280, so normally rejected.
        $body = str_repeat('a', 300);

        $this->actingAs($user)
            ->from(route('client.social.composer'))
            ->post(route('client.social.posts.store'), [
                'body' => $body,
                'target_accounts' => [$account->id],
            ])
            ->assertSessionHasErrors('body');
    }

    #[Test]
    public function a_body_within_the_strictest_limit_is_accepted(): void
    {
        [$user, $account] = $this->clientWithAccount('twitter');

        $this->actingAs($user)
            ->post(route('client.social.posts.store'), [
                'body' => str_repeat('a', 200),
                'target_accounts' => [$account->id],
            ])
            ->assertSessionHasNoErrors();
    }

    /**
     * The strictest network among several decides, and its NAME is reported —
     * "too long" without saying which destination caused it leaves the author
     * guessing which of four accounts to shorten for.
     */
    #[Test]
    public function the_error_names_the_network_that_set_the_limit(): void
    {
        [$user, $twitter] = $this->clientWithAccount('twitter');
        $facebook = SocialAccount::create([
            'workspace_id' => $twitter->workspace_id,
            'network' => 'facebook',
            'account_id' => 'fb1',
            'name' => 'FB',
            'access_token' => 'tok',
            'active' => true,
        ]);

        $this->actingAs($user)
            ->from(route('client.social.composer'))
            ->post(route('client.social.posts.store'), [
                'body' => str_repeat('a', 500),
                'target_accounts' => [$twitter->id, $facebook->id],
            ])
            ->assertSessionHasErrors(['body' => 'The post is 500 characters, but twitter allows 280.']);
    }

    // ── Exposure to the frontend ──────────────────────────────────────────

    /**
     * ⚠️ Asserts the PROP, not just the class method. The composer branch will
     * consume `networkCapabilities`; if the controller stopped sending it, the
     * UI would silently fall back to `?? 5000` for every network and offer
     * YouTube for image posts — a wrong default rather than an error.
     */
    #[Test]
    public function the_composer_page_receives_the_capability_prop(): void
    {
        [$user] = $this->clientWithAccount('facebook');

        $this->actingAs($user)
            ->get(route('client.social.composer'))
            ->assertInertia(function ($page) {
                $caps = $page->toArray()['props']['networkCapabilities'] ?? null;

                $this->assertIsArray($caps, 'networkCapabilities was not sent to the composer');
                $this->assertArrayHasKey('instagram', $caps);
                $this->assertArrayNotHasKey('pinterest', $caps, 'a network with no driver was offered');

                // The shape the composer branch will read.
                foreach (['supports_image', 'supports_video', 'supports_carousel',
                    'carousel_min', 'carousel_max', 'image_ratio_min', 'image_ratio_max',
                    'video_ratio_min', 'video_ratio_max', 'max_video_seconds',
                    'max_file_bytes', 'char_limit'] as $key) {
                    $this->assertArrayHasKey($key, $caps['instagram'], "missing key: {$key}");
                }

                $this->assertSame(1.91, $caps['instagram']['image_ratio_max']);
                $this->assertSame(2200, $caps['instagram']['char_limit']);
            });
    }

    #[Test]
    public function the_edit_page_receives_the_capability_prop(): void
    {
        [$user, $account] = $this->clientWithAccount('facebook');

        $post = SocialPost::create([
            'workspace_id' => $account->workspace_id,
            'body' => 'draft',
            'media_urls' => [],
            'target_accounts' => [$account->id],
            'status' => 'draft',
        ]);

        $this->actingAs($user)
            ->get(route('client.social.posts.edit', $post->id))
            ->assertInertia(fn ($page) => $this->assertArrayHasKey(
                'networkCapabilities', $page->toArray()['props']
            ));
    }

    // ── The update() path ─────────────────────────────────────────────────

    /**
     * ⚠️ These three exist because a MUTATION TEST proved the suite could not
     * see this path. Deleting assertBodyFitsEveryNetwork() from update()
     * entirely left NetworkCapabilitiesTest green at 13/13 — every char-limit
     * assertion above goes through store(). The merge that brought the
     * capability matrix together with the IDOR guard conflicted in exactly this
     * method, so "both blocks are present" needed to be a test, not a reading
     * of the diff.
     */
    #[Test]
    public function char_limit_validation_also_guards_the_update_path(): void
    {
        [$user, $account] = $this->clientWithAccount('twitter');
        $post = $this->draftFor($account);

        $this->actingAs($user)
            ->from(route('client.social.posts.edit', $post->id))
            ->put(route('client.social.posts.update', $post->id), [
                'body' => str_repeat('a', 300),
                'target_accounts' => [$account->id],
            ])
            ->assertSessionHasErrors('body');
    }

    /** Positive control: same route, same verb, same user — a fitting body saves. */
    #[Test]
    public function a_body_within_the_limit_updates_normally(): void
    {
        [$user, $account] = $this->clientWithAccount('twitter');
        $post = $this->draftFor($account);

        $this->actingAs($user)
            ->put(route('client.social.posts.update', $post->id), [
                'body' => str_repeat('a', 200),
                'target_accounts' => [$account->id],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('client.social.posts.index'));
    }

    /**
     * ⚠️ Pins the ORDER the merge chose: ownership is checked before the
     * char-limit rule. assertBodyFitsEveryNetwork() resolves ids to networks
     * with an UNSCOPED SocialAccount query, so if it ran first, a foreign
     * account id would reach that lookup and its network would shape the error
     * ("... but twitter allows 280") — an oracle for which network an arbitrary
     * id in another workspace belongs to. The body here is over EVERY limit, so
     * both rules would fire; only the ordering decides which message comes back.
     */
    #[Test]
    public function ownership_is_reported_before_the_char_limit_leaks_a_foreign_network(): void
    {
        [$user, $own] = $this->clientWithAccount('twitter');
        $post = $this->draftFor($own);

        ['workspace' => $otherWorkspace] = $this->createWorkspaceContext();
        $foreign = SocialAccount::create([
            'workspace_id' => $otherWorkspace->id,
            'network' => 'twitter',
            'account_id' => 'foreign-1',
            'name' => 'Foreign',
            'access_token' => 'tok',
            'active' => true,
        ]);

        $this->actingAs($user)
            ->from(route('client.social.posts.edit', $post->id))
            ->put(route('client.social.posts.update', $post->id), [
                'body' => str_repeat('a', 5000),
                'target_accounts' => [$own->id, $foreign->id],
            ])
            ->assertSessionHasErrors('target_accounts')
            ->assertSessionDoesntHaveErrors('body');
    }

    private function draftFor(SocialAccount $account): SocialPost
    {
        return SocialPost::create([
            'workspace_id' => $account->workspace_id,
            'body' => 'draft',
            'media_urls' => [],
            'target_accounts' => [$account->id],
            'status' => 'draft',
        ]);
    }

    /** @return array{0: User, 1: SocialAccount} */
    private function clientWithAccount(string $network): array
    {
        ['workspace' => $workspace, 'user' => $user] = $this->createWorkspaceContext();

        $account = SocialAccount::create([
            'workspace_id' => $workspace->id,
            'network' => $network,
            'account_id' => 'acct-1',
            'name' => 'Test',
            'access_token' => 'tok',
            'active' => true,
        ]);

        return [$user, $account];
    }
}
