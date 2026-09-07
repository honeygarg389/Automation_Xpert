<?php

namespace App\Modules\Social\Jobs;

use App\Jobs\Middleware\EstablishesWorkspaceContext;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Services\OAuth\OAuthManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RefreshSocialTokensJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * CROSS-TENANT BY DESIGN — a decision, not an omission.
     *
     * This job's entire function is to scan every workspace for due work.
     * Giving it a tenant context would silently reduce it to one tenant's.
     * Declared explicitly so it is counted rather than looking like a job
     * somebody forgot to scope.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [EstablishesWorkspaceContext::crossTenant(
            'reason: refreshes expiring OAuth tokens across EVERY workspace; a token left unrefreshed silently breaks publishing for whichever tenant owns it',
        )];
    }

    public function handle(OAuthManager $oauthManager): void
    {
        // Networks that support programmatic refresh
        $refreshable = ['twitter', 'youtube', 'linkedin'];

        SocialAccount::where('active', true)
            ->whereIn('network', $refreshable)
            ->where('token_expires_at', '<', now()->addDay())
            ->whereNotNull('refresh_token')
            ->chunkById(100, function ($accounts) use ($oauthManager) {
                foreach ($accounts as $account) {
                    try {
                        $refreshed = $oauthManager->refresh($account->network, $account->refresh_token);

                        $account->update([
                            'access_token' => $refreshed['access_token'],
                            'refresh_token' => $refreshed['refresh_token'] ?? $account->refresh_token,
                            'token_expires_at' => isset($refreshed['expires_in'])
                                ? now()->addSeconds((int) $refreshed['expires_in'])
                                : null,
                        ]);

                        Log::info('Social token refreshed', ['network' => $account->network, 'account_id' => $account->id]);
                    } catch (\Throwable $e) {
                        Log::warning('Social token refresh failed', [
                            'network' => $account->network,
                            'account_id' => $account->id,
                            'error' => $e->getMessage(),
                        ]);
                        $account->update(['active' => false]);
                    }
                }
            });
    }
}
