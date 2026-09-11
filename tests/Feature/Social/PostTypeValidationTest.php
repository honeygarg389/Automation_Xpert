<?php

namespace Tests\Feature\Social;

use App\Models\User;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Models\SocialPost;
use App\Modules\Social\Support\DriverCapabilities;
use App\Modules\Social\Support\NetworkCapabilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ═══ POST TYPE IS GATED ON WHAT THE DRIVERS SEND ════════════════════════════
 *
 * ⚠️ The interesting assertions here are the ones that look WRONG against the
 * platforms' own APIs. Instagram documents carousels (2-10) and LinkedIn
 * documents images; both are REJECTED, because InstagramSocialDriver sends
 * image_url => mediaUrls[0] and LinkedInDriver hardcodes
 * shareMediaCategory => 'NONE'. Allowing the selection because the vendor
 * permits it is how media is dropped between save and publish with no error,
 * no failed row, and nothing for the author to see.
 *
 * If a future branch adds real driver support and forgets to update
 * DriverCapabilities, the pinned-values test below fails and says so.
 */
class PostTypeValidationTest extends TestCase
{
    use RefreshDatabase;

    // ── The driver-reality map itself ─────────────────────────────────────

    /**
     * ⚠️ GUARD TEST. These values are a claim about code in
     * app/Modules/Social/Services/Drivers, not about vendor documentation.
     * When Branch 4 teaches a driver to publish video or a real carousel, this
     * test fails until the map is updated in the same commit — which is the
     * point, because the alternative failure is silent: the driver gains the
     * ability and the composer never offers it.
     */
    #[Test]
    public function driver_reality_is_pinned_to_what_the_drivers_actually_send(): void
    {
        $expected = [
            'facebook' => ['image' => true, 'carousel' => true, 'video' => false],
            // Branch 4 (feature/instagram-reels-carousel): image-only carousel
            // and single-Reel video are now implemented. Still false for
            // mixed image+video carousels — the driver refuses that combination
            // (post_type is post-level, not per-item; see the driver docblock).
            'instagram' => ['image' => true, 'carousel' => true, 'video' => true],
            'linkedin' => ['image' => false, 'carousel' => false, 'video' => false],
            'twitter' => ['image' => false, 'carousel' => false, 'video' => false],
            'youtube' => ['image' => false, 'carousel' => false, 'video' => true],
        ];

        foreach ($expected as $network => $caps) {
            foreach ($caps as $capability => $want) {
                $this->assertSame(
                    $want,
                    DriverCapabilities::supports($network, $capability),
                    "driver reality changed for {$network}.{$capability}"
                );
            }
        }
    }

    #[Test]
    public function an_unknown_network_supports_nothing(): void
    {
        $this->assertFalse(DriverCapabilities::supports('myspace', 'image'));
        $this->assertFalse(DriverCapabilities::supports('myspace', 'video'));
    }

    #[Test]
    public function eligible_networks_reflect_driver_reality_not_the_platform_matrix(): void
    {
        $this->assertSame(['facebook', 'instagram'], DriverCapabilities::eligibleNetworks('image', 'single'));
        $this->assertSame(['facebook', 'instagram'], DriverCapabilities::eligibleNetworks('image', 'carousel'));
        $this->assertSame(['instagram', 'youtube'], DriverCapabilities::eligibleNetworks('video'));

        // text is deliverable everywhere, which is why AI posts default to it.
        $this->assertSame(NetworkCapabilities::DRIVER_BACKED, DriverCapabilities::eligibleNetworks('text'));
    }

    // ── Rejections, each with a positive control ──────────────────────────

    #[Test]
    public function an_image_post_to_linkedin_is_rejected(): void
    {
        [$user, $account] = $this->clientWithAccount('linkedin');

        $this->actingAs($user)
            ->from(route('client.social.composer'))
            ->post(route('client.social.posts.store'), [
                'body' => 'hello',
                'target_accounts' => [$account->id],
                'post_type' => 'image',
                'media_type' => 'single',
            ])
            ->assertSessionHasErrors('target_accounts');
    }

    /**
     * ⚠️ SUPERSEDED BY feature/instagram-reels-carousel. This test used to
     * assert the driver-reality GAP: the platform allowed carousels but the
     * driver sent mediaUrls[0] only. That gap is now closed — the driver
     * builds real carousel children — so the correct assertion flipped from
     * "rejected" to "accepted". Keeping the method name's history in this
     * comment rather than silently deleting the test, since a future reader
     * diffing test names would otherwise wonder where it went.
     */
    #[Test]
    public function a_carousel_to_instagram_is_now_accepted_since_the_driver_implements_it(): void
    {
        [$user, $account] = $this->clientWithAccount('instagram');

        $this->assertTrue(NetworkCapabilities::supports('instagram', 'supports_carousel'));
        $this->assertTrue(DriverCapabilities::supports('instagram', 'carousel'));

        $this->actingAs($user)
            ->post(route('client.social.posts.store'), [
                'body' => 'hello',
                'target_accounts' => [$account->id],
                'post_type' => 'image',
                'media_type' => 'carousel',
                'media_urls' => ['https://cdn.example.com/a.jpg', 'https://cdn.example.com/b.jpg'],
            ])
            ->assertSessionHasNoErrors();
    }

    #[Test]
    public function a_video_post_to_facebook_is_rejected(): void
    {
        [$user, $account] = $this->clientWithAccount('facebook');

        $this->actingAs($user)
            ->from(route('client.social.composer'))
            ->post(route('client.social.posts.store'), [
                'body' => 'hello',
                'target_accounts' => [$account->id],
                'post_type' => 'video',
            ])
            ->assertSessionHasErrors('target_accounts');
    }

    #[Test]
    public function the_rejection_names_every_blocked_network(): void
    {
        [$user, $linkedin] = $this->clientWithAccount('linkedin');
        $twitter = $this->accountFor($linkedin->workspace_id, 'twitter', 'tw1');

        $this->actingAs($user)
            ->from(route('client.social.composer'))
            ->post(route('client.social.posts.store'), [
                'body' => 'hello',
                'target_accounts' => [$linkedin->id, $twitter->id],
                'post_type' => 'image',
                'media_type' => 'single',
            ])
            ->assertSessionHasErrors(['target_accounts' => 'Cannot publish a image post to: linkedin, twitter. Remove those accounts, or change the post type.']);
    }

    /** Positive control: the same route, verb and shape succeeds where the driver can deliver. */
    #[Test]
    public function an_image_post_to_facebook_is_accepted(): void
    {
        [$user, $account] = $this->clientWithAccount('facebook');

        $this->actingAs($user)
            ->post(route('client.social.posts.store'), [
                'body' => 'hello',
                'target_accounts' => [$account->id],
                'post_type' => 'image',
                'media_type' => 'single',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('image', SocialPost::latest('id')->first()->post_type);
    }

    #[Test]
    public function a_video_post_to_youtube_is_accepted(): void
    {
        [$user, $account] = $this->clientWithAccount('youtube');

        $this->actingAs($user)
            ->post(route('client.social.posts.store'), [
                'body' => 'hello',
                'target_accounts' => [$account->id],
                'post_type' => 'video',
            ])
            ->assertSessionHasNoErrors();

        $post = SocialPost::latest('id')->first();
        $this->assertSame('video', $post->post_type);
        // ⚠️ Asserts the STORED ROW. media_type must be null for video, not
        // carried over from a previous choice.
        $this->assertNull($post->media_type);
    }

    #[Test]
    public function a_text_post_is_accepted_everywhere(): void
    {
        [$user, $linkedin] = $this->clientWithAccount('linkedin');
        $twitter = $this->accountFor($linkedin->workspace_id, 'twitter', 'tw1');

        $this->actingAs($user)
            ->post(route('client.social.posts.store'), [
                'body' => 'hello',
                'target_accounts' => [$linkedin->id, $twitter->id],
                'post_type' => 'text',
            ])
            ->assertSessionHasNoErrors();
    }

    // ── The update() path ─────────────────────────────────────────────────

    #[Test]
    public function update_enforces_the_same_rule(): void
    {
        [$user, $facebook] = $this->clientWithAccount('facebook');
        $post = SocialPost::create([
            'workspace_id' => $facebook->workspace_id,
            'body' => 'draft', 'media_urls' => [],
            'target_accounts' => [$facebook->id],
            'post_type' => 'image', 'media_type' => 'single', 'status' => 'draft',
        ]);

        $this->actingAs($user)
            ->from(route('client.social.posts.edit', $post->id))
            ->put(route('client.social.posts.update', $post->id), [
                'body' => 'updated',
                'target_accounts' => [$facebook->id],
                'post_type' => 'video',
            ])
            ->assertSessionHasErrors('target_accounts');
    }

    /**
     * ⚠️ An edit that does not resend post_type must keep the row's type, not
     * silently downgrade it to 'text' — the same absent-nullable-key trap that
     * once made scheduled_at throw here.
     */
    #[Test]
    public function update_without_post_type_keeps_the_existing_type(): void
    {
        [$user, $facebook] = $this->clientWithAccount('facebook');
        $post = SocialPost::create([
            'workspace_id' => $facebook->workspace_id,
            'body' => 'draft', 'media_urls' => [],
            'target_accounts' => [$facebook->id],
            'post_type' => 'image', 'media_type' => 'carousel', 'status' => 'draft',
        ]);

        $this->actingAs($user)
            ->put(route('client.social.posts.update', $post->id), [
                'body' => 'updated',
                'target_accounts' => [$facebook->id],
            ])
            ->assertSessionHasNoErrors();

        $fresh = $post->fresh();
        $this->assertSame('image', $fresh->post_type);
        $this->assertSame('carousel', $fresh->media_type);
    }

    /**
     * post_type is editable in exactly the states the form is reachable in.
     * Editing is already gated server-side on BOTH edit() and update(), so this
     * pins the states rather than adding a new lock: draft, scheduled and failed
     * are editable; publishing and published 403 before any validation runs.
     *
     * ⚠️ `failed` matters. A failed post never reached the provider, so its type
     * must stay changeable — locking it on "has been sent" would strand it.
     */
    #[Test]
    public function post_type_is_editable_in_every_state_the_edit_form_is_reachable_in(): void
    {
        [$user, $facebook] = $this->clientWithAccount('facebook');

        foreach (['draft', 'scheduled', 'failed'] as $status) {
            $post = SocialPost::create([
                'workspace_id' => $facebook->workspace_id,
                'body' => 'draft', 'media_urls' => [],
                'target_accounts' => [$facebook->id],
                'post_type' => 'text', 'status' => $status,
                'scheduled_at' => $status === 'scheduled' ? now()->addDay() : null,
            ]);

            $this->actingAs($user)
                ->get(route('client.social.posts.edit', $post->id))
                ->assertOk();

            $this->actingAs($user)
                ->put(route('client.social.posts.update', $post->id), [
                    'body' => 'updated',
                    'target_accounts' => [$facebook->id],
                    'post_type' => 'image',
                    'media_type' => 'single',
                ])
                ->assertSessionHasNoErrors();

            $this->assertSame('image', $post->fresh()->post_type, "status {$status} should allow a type change");
        }
    }

    /** The other half: a sent post cannot have its type changed, on either verb. */
    #[Test]
    public function a_published_post_cannot_reach_the_edit_form_or_the_update_route(): void
    {
        [$user, $facebook] = $this->clientWithAccount('facebook');

        foreach (['publishing', 'published'] as $status) {
            $post = SocialPost::create([
                'workspace_id' => $facebook->workspace_id,
                'body' => 'sent', 'media_urls' => [],
                'target_accounts' => [$facebook->id],
                'post_type' => 'text', 'status' => $status,
            ]);

            $this->actingAs($user)->get(route('client.social.posts.edit', $post->id))->assertForbidden();
            $this->actingAs($user)
                ->put(route('client.social.posts.update', $post->id), [
                    'body' => 'updated', 'target_accounts' => [$facebook->id], 'post_type' => 'image',
                ])
                ->assertForbidden();

            $this->assertSame('text', $post->fresh()->post_type);
        }
    }

    // ── Carousel count intersection ───────────────────────────────────────

    #[Test]
    public function the_carousel_range_is_the_tightest_across_networks(): void
    {
        $this->assertSame(
            ['min' => 2, 'max' => 4, 'unverified' => []],
            NetworkCapabilities::carouselRange(['twitter', 'instagram', 'linkedin'])
        );
    }

    /**
     * ⚠️ THE NULL TRAP. Facebook's carousel_max is null — Meta documents no cap
     * for attached_media. Treating that as Infinity in a min() would make
     * Facebook silently non-constraining; treating it as 0 would block
     * everything. It must contribute NOTHING and be reported as unverified.
     */
    #[Test]
    public function an_unverified_maximum_is_skipped_and_reported_not_treated_as_unlimited(): void
    {
        $this->assertNull(NetworkCapabilities::NETWORKS['facebook']['carousel_max']);

        $facebookOnly = NetworkCapabilities::carouselRange(['facebook']);
        $this->assertSame(2, $facebookOnly['min']);
        $this->assertNull($facebookOnly['max'], 'an unverified max must stay null, never become a number');
        $this->assertSame(['facebook'], $facebookOnly['unverified']);

        // Alongside a verified network, Facebook must not widen the bound.
        $mixed = NetworkCapabilities::carouselRange(['facebook', 'instagram']);
        $this->assertSame(10, $mixed['max'], 'facebook must not raise instagram\'s ceiling');
        $this->assertSame(['facebook'], $mixed['unverified']);
    }

    #[Test]
    public function a_carousel_below_the_minimum_is_rejected(): void
    {
        [$user, $account] = $this->clientWithAccount('facebook');

        $this->actingAs($user)
            ->from(route('client.social.composer'))
            ->post(route('client.social.posts.store'), [
                'body' => 'hello',
                'target_accounts' => [$account->id],
                'post_type' => 'image',
                'media_type' => 'carousel',
                'media_urls' => ['https://cdn.example.com/only-one.jpg'],
            ])
            ->assertSessionHasErrors('media_urls');
    }

    // ── Image ratio, via getimagesize() on a real file ────────────────────

    /**
     * Instagram accepts 4:5 (0.8) to 1.91:1. A 3:1 banner is outside that, and
     * the check must read the ACTUAL PIXELS rather than trusting the filename.
     */
    #[Test]
    public function an_image_outside_the_ratio_window_is_rejected(): void
    {
        [$user, $account] = $this->clientWithAccount('instagram');
        $url = $this->storeImage('wide.jpg', 900, 300); // 3:1

        $this->actingAs($user)
            ->from(route('client.social.composer'))
            ->post(route('client.social.posts.store'), [
                'body' => 'hello',
                'target_accounts' => [$account->id],
                'post_type' => 'image',
                'media_type' => 'single',
                'media_urls' => [$url],
            ])
            ->assertSessionHasErrors('media_urls');
    }

    /** Positive control: a 1:1 image inside the window saves. */
    #[Test]
    public function an_image_inside_the_ratio_window_is_accepted(): void
    {
        [$user, $account] = $this->clientWithAccount('instagram');
        $url = $this->storeImage('square.jpg', 600, 600); // 1:1

        $this->actingAs($user)
            ->post(route('client.social.posts.store'), [
                'body' => 'hello',
                'target_accounts' => [$account->id],
                'post_type' => 'image',
                'media_type' => 'single',
                'media_urls' => [$url],
            ])
            ->assertSessionHasNoErrors();
    }

    /** Facebook documents no ratio bounds, so nothing is enforced for it alone. */
    #[Test]
    public function no_ratio_is_enforced_when_no_selected_network_documents_one(): void
    {
        [$user, $account] = $this->clientWithAccount('facebook');
        $url = $this->storeImage('wide.jpg', 900, 300);

        $this->actingAs($user)
            ->post(route('client.social.posts.store'), [
                'body' => 'hello',
                'target_accounts' => [$account->id],
                'post_type' => 'image',
                'media_type' => 'single',
                'media_urls' => [$url],
            ])
            ->assertSessionHasNoErrors();
    }

    // ── Declared type vs actual file ──────────────────────────────────────

    #[Test]
    public function a_video_declaration_carrying_an_image_file_is_rejected_at_save(): void
    {
        [$user, $account] = $this->clientWithAccount('youtube');
        $url = $this->storeImage('not-a-video.jpg', 600, 600);

        $this->actingAs($user)
            ->from(route('client.social.composer'))
            ->post(route('client.social.posts.store'), [
                'body' => 'hello',
                'target_accounts' => [$account->id],
                'post_type' => 'video',
                'media_urls' => [$url],
            ])
            ->assertSessionHasErrors('media_urls');
    }

    // ── bulkStore(): the gap this branch closes ───────────────────────────

    /**
     * ⚠️ THE CHAR LIMIT WAS NEVER ENFORCED ON THIS PATH. buildPlanMessages()
     * only ASKS the model to fit the shortest limit; an LLM returning 400
     * characters for an X account was persisted without objection. store() and
     * update() have enforced it all along.
     */
    #[Test]
    public function bulk_store_now_enforces_the_character_limit(): void
    {
        [$user, $twitter] = $this->clientWithAccount('twitter');

        $response = $this->actingAs($user)
            ->postJson(route('client.social.posts.bulk'), [
                'posts' => [[
                    'body' => str_repeat('a', 400), // over X's 280
                    'target_accounts' => [$twitter->id],
                ]],
            ])
            ->assertStatus(422);

        $this->assertSame(
            ['The post is 400 characters, but twitter allows 280.'],
            $response->json('errors.posts\.0\.body') ?? $response->json()['errors']['posts.0.body'] ?? null
        );

        $this->assertSame(0, SocialPost::count(), 'nothing may be written when validation fails');
    }

    /** Positive control: a fitting body still creates the posts. */
    #[Test]
    public function bulk_store_accepts_a_body_within_the_limit(): void
    {
        [$user, $twitter] = $this->clientWithAccount('twitter');

        $this->actingAs($user)
            ->postJson(route('client.social.posts.bulk'), [
                'posts' => [[
                    'body' => str_repeat('a', 200),
                    'target_accounts' => [$twitter->id],
                ]],
            ])
            ->assertOk();

        $this->assertSame(1, SocialPost::count());
    }

    /**
     * AI-planned posts are text-only by construction — buildPlanMessages() asks
     * for no media field and bulkStore() writes media_urls => []. Asserts the
     * STORED ROW, since mass assignment discards silently.
     */
    #[Test]
    public function bulk_store_defaults_ai_posts_to_text(): void
    {
        [$user, $linkedin] = $this->clientWithAccount('linkedin');

        $this->actingAs($user)
            ->postJson(route('client.social.posts.bulk'), [
                'posts' => [[
                    'body' => 'a planned post',
                    'target_accounts' => [$linkedin->id],
                ]],
            ])
            ->assertOk();

        $post = SocialPost::latest('id')->first();
        $this->assertSame('text', $post->post_type);
        $this->assertNull($post->media_type);
        $this->assertTrue((bool) $post->ai_generated);
    }

    // ── helpers ───────────────────────────────────────────────────────────

    /** @return array{0: User, 1: SocialAccount} */
    private function clientWithAccount(string $network): array
    {
        ['workspace' => $workspace, 'user' => $user] = $this->createWorkspaceContext();

        return [$user, $this->accountFor($workspace->id, $network, 'acct-1')];
    }

    private function accountFor(int $workspaceId, string $network, string $externalId): SocialAccount
    {
        return SocialAccount::create([
            'workspace_id' => $workspaceId,
            'network' => $network,
            'account_id' => $externalId,
            'name' => ucfirst($network),
            'access_token' => 'tok',
            'active' => true,
        ]);
    }

    /** Writes a real JPEG so getimagesize() has actual pixels to read. */
    private function storeImage(string $name, int $width, int $height): string
    {
        $dir = storage_path('app/public/media');
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $im = imagecreatetruecolor($width, $height);
        imagejpeg($im, $dir.'/'.$name);
        imagedestroy($im);

        return rtrim(config('app.url'), '/').'/storage/media/'.$name;
    }

    protected function tearDown(): void
    {
        foreach (glob(storage_path('app/public/media/*.jpg')) ?: [] as $f) {
            @unlink($f);
        }

        parent::tearDown();
    }
}
