<?php

namespace Tests\Feature\Social;

use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Models\SocialPost;
use App\Modules\Social\Services\SocialPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SocialPublisherLegacyNetworkTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_legacy_tiktok_account_fails_gracefully_without_a_driver(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();

        $account = SocialAccount::create([
            'workspace_id' => $workspace->id,
            'network' => 'tiktok',
            'account_id' => 'legacy-tiktok-account',
            'name' => 'Legacy TikTok',
            'access_token' => 'unused-token',
            'active' => true,
        ]);

        $post = SocialPost::create([
            'workspace_id' => $workspace->id,
            'body' => 'Legacy post',
            'target_accounts' => [$account->id],
            'status' => 'publishing',
        ]);

        app(SocialPublisher::class)->publish($post);

        $this->assertDatabaseHas('social_media_post_accounts', [
            'post_id' => $post->id,
            'social_account_id' => $account->id,
            'status' => 'failed',
            'error' => 'No driver for network tiktok.',
        ]);
        $this->assertSame('failed', $post->fresh()->status);
    }
}
