<?php

namespace App\Modules\Social\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Services\Drivers\FacebookDriver;
use App\Modules\Social\Services\Drivers\InstagramSocialDriver;
use App\Modules\Social\Services\Drivers\LinkedInDriver;
use App\Modules\Social\Services\Drivers\TikTokDriver;
use App\Modules\Social\Services\Drivers\TwitterDriver;
use App\Modules\Social\Services\Drivers\YoutubeDriver;
use App\Modules\Social\Services\OAuth\OAuthManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;
use Inertia\Inertia;
use Inertia\Response;

class SocialAccountController extends Controller
{
    private array $drivers;

    public function __construct(private readonly OAuthManager $oauth)
    {
        $this->drivers = [
            'facebook' => new FacebookDriver,
            'instagram' => new InstagramSocialDriver,
            'linkedin' => new LinkedInDriver,
            'twitter' => new TwitterDriver,
            'youtube' => new YoutubeDriver,
            'tiktok' => new TikTokDriver,
        ];
    }

    private function workspaceId(Request $request): int
    {
        return (int) ($request->user()->current_workspace_id ?? $request->user()->workspace_id);
    }

    public function index(Request $request): Response
    {
        $wid = $this->workspaceId($request);
        $accounts = SocialAccount::where('workspace_id', $wid)->get();

        return Inertia::render('Social/Accounts/Index', ['accounts' => $accounts]);
    }

    public function connect(Request $request, string $network): RedirectResponse
    {
        $validNetworks = ['facebook', 'instagram', 'linkedin', 'twitter', 'youtube', 'tiktok'];
        abort_unless(in_array($network, $validNetworks, true), 404);

        Session::put('social_oauth_workspace', $this->workspaceId($request));

        $callbackUrl = route('client.social.oauth.callback', $network);

        try {
            $authUrl = $this->oauth->getAuthUrl($network, $this->workspaceId($request), $callbackUrl);
        } catch (\RuntimeException $e) {
            return redirect()->route('client.social.accounts.index')
                ->with('error', "OAuth for {$network} is not configured. Please contact your administrator.");
        }

        return redirect($authUrl);
    }

    public function callback(Request $request, string $network): RedirectResponse
    {
        $code     = $request->query('code');
        $state    = $request->query('state');
        $error    = $request->query('error');
        $wid      = Session::get('social_oauth_workspace', $this->workspaceId($request));
        $stored   = Session::pull('social_oauth_state', []);

        if ($error || ! $code) {
            return redirect()->route('client.social.accounts.index')->with('error', 'OAuth failed: '.($error ?? 'No code received'));
        }

        // Verify state to prevent OAuth CSRF / account-linking hijack
        if (empty($stored['state']) || ! hash_equals($stored['state'], (string) $state)) {
            return redirect()->route('client.social.accounts.index')->with('error', 'Invalid OAuth state. Please try connecting again.');
        }

        $callbackUrl = route('client.social.oauth.callback', $network);
        $tokens = $this->oauth->exchangeCode($network, $code, $callbackUrl, $stored);

        if (empty($tokens['access_token'])) {
            return redirect()->route('client.social.accounts.index')->with('error', 'Failed to obtain access token.');
        }

        $driver = $this->drivers[$network] ?? null;
        $accountInfo = $driver ? $driver->fetchAccountInfo($tokens['access_token']) : ['account_id' => '', 'name' => '', 'picture_url' => null];

        // For Facebook / Instagram: fetch pages the user manages and upsert each one.
        if (in_array($network, ['facebook', 'instagram'])) {
            $fields = $network === 'instagram'
                ? 'id,name,access_token,picture,instagram_business_account{id,name,username,profile_picture_url}'
                : 'id,name,access_token,picture';

            $pagesResp = Http::get('https://graph.facebook.com/v19.0/me/accounts', [
                'access_token' => $tokens['access_token'],
                'fields' => $fields,
            ])->json();

            // Graph API returned an error — surface it to the user.
            if (isset($pagesResp['error'])) {
                $msg = $pagesResp['error']['message'] ?? 'Unknown Graph API error.';

                return redirect()->route('client.social.accounts.index')
                    ->with('error', 'Could not fetch your '.ucfirst($network).' pages: '.$msg);
            }

            $pages = $pagesResp['data'] ?? [];

            if (empty($pages)) {
                return redirect()->route('client.social.accounts.index')
                    ->with('error', 'No '.ucfirst($network).' Pages were found on your account. Make sure you are an admin of at least one Page and that your Meta App has the pages_show_list permission approved.');
            }

            $connected = 0;

            foreach ($pages as $page) {
                if ($network === 'instagram') {
                    $igAccount = $page['instagram_business_account'] ?? null;
                    if (! $igAccount) {
                        // This page has no linked Instagram Business account — skip it.
                        continue;
                    }

                    $igName = $igAccount['username']
                        ? '@'.$igAccount['username']
                        : ($igAccount['name'] ?? $page['name']);

                    SocialAccount::updateOrCreate(
                        ['workspace_id' => $wid, 'network' => 'instagram', 'account_id' => $igAccount['id']],
                        [
                            'name' => $igName,
                            'picture_url' => $igAccount['profile_picture_url'] ?? ($page['picture']['data']['url'] ?? null),
                            'access_token' => $page['access_token'], // page token is used for IG Graph API calls
                            'refresh_token' => null,
                            'token_expires_at' => null,
                            'active' => true,
                        ]
                    );
                } else {
                    SocialAccount::updateOrCreate(
                        ['workspace_id' => $wid, 'network' => 'facebook', 'account_id' => $page['id']],
                        [
                            'name' => $page['name'],
                            'picture_url' => $page['picture']['data']['url'] ?? null,
                            'access_token' => $page['access_token'],
                            'refresh_token' => null,
                            'token_expires_at' => null,
                            'active' => true,
                        ]
                    );
                }

                $connected++;
            }

            if ($connected === 0 && $network === 'instagram') {
                return redirect()->route('client.social.accounts.index')
                    ->with('error', 'No Instagram Business accounts were found linked to your Facebook Pages. Make sure your Instagram account is set to Business type and connected to a Facebook Page.');
            }

            return redirect()->route('client.social.accounts.index')
                ->with('success', $connected.' '.ucfirst($network).' account(s) connected.');
        }

        SocialAccount::updateOrCreate(
            ['workspace_id' => $wid, 'network' => $network, 'account_id' => $accountInfo['account_id']],
            [
                'name' => $accountInfo['name'],
                'picture_url' => $accountInfo['picture_url'],
                'access_token' => $tokens['access_token'],
                'refresh_token' => $tokens['refresh_token'] ?? null,
                'token_expires_at' => isset($tokens['expires_in']) ? now()->addSeconds((int) $tokens['expires_in']) : null,
                'active' => true,
            ]
        );

        return redirect()->route('client.social.accounts.index')->with('success', ucfirst($network).' account connected.');
    }

    public function disconnect(Request $request, SocialAccount $account): RedirectResponse
    {
        abort_unless((int) $account->workspace_id === $this->workspaceId($request), 403);
        $account->delete();

        return back()->with('success', 'Account disconnected.');
    }
}
