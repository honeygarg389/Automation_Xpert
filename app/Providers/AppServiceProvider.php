<?php

namespace App\Providers;

use App\Events\AutomationFailed;
use App\Events\AutomationWebhookReceived;
use App\Events\CampaignCompleted;
use App\Events\CommerceEventReceived;
use App\Events\ContactCreated;
use App\Events\ConversationAssigned;
use App\Events\MessageReceived;
use App\Events\PlanChanged;
use App\Events\SubscriptionCancelled;
use App\Events\SubscriptionExpired;
use App\Events\SubscriptionRenewed;
use App\Events\SubscriptionStarted;
use App\Events\TrialEnding;
use App\Listeners\AutomationTriggerListener;
use App\Listeners\AutoReplyListener;
use App\Listeners\DispatchOutboundWebhookListener;
use App\Listeners\LogSuccessfulLogin;
use App\Listeners\SendAutomationFailedNotification;
use App\Listeners\SendCampaignCompletedNotification;
use App\Listeners\SendConversationAssignedNotification;
use App\Listeners\SendNewMessageNotification;
use App\Listeners\SendPlanChangedNotification;
use App\Listeners\SendSubscriptionCancelledNotification;
use App\Listeners\SendSubscriptionExpiredNotification;
use App\Listeners\SendSubscriptionRenewedNotification;
use App\Listeners\SendSubscriptionStartedNotification;
use App\Listeners\SendTrialEndingNotification;
use App\Listeners\SendWelcomeNotification;
use App\Models\Client;
use App\Models\Workspace;
use App\Modules\Flows\Events\WhatsappFlowSubmitted;
use App\Modules\Flows\Listeners\WhatsappFlowSubmissionListener;
use App\Modules\Shared\Services\ChannelManager;
use App\Modules\SmartQr\Listeners\RecordQrAttributionListener;
use App\Services\Billing\BillingGatewayRegistry;
use App\Services\StorageManager;
use App\Support\Http\ConnectionExceptionScrubber;
use App\Support\WorkspaceContext;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Registered;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // On a fresh deploy the database (and its sessions/cache/queue tables)
        // does not exist yet, which would otherwise break the installer's own
        // session + CSRF. Until the app is installed, fall back to filesystem
        // drivers so the setup wizard works against an empty database.
        if (! config('app.installed')) {
            config([
                'session.driver' => 'file',
                'cache.default' => 'array',
                'queue.default' => 'sync',
            ]);
        }

        $this->app->singleton(BillingGatewayRegistry::class, fn () => new BillingGatewayRegistry);
        $this->app->singleton(StorageManager::class);
        $this->app->singleton(ChannelManager::class, fn () => new ChannelManager);
    }

    public function boot(): void
    {
        $this->flushWorkspaceContextBetweenProcesses();
        $this->configureHttpClientSsl();
        $this->scrubCredentialsFromConnectionFailures();
        $this->forceHttpsForWebhookUrls();

        Gate::define('viewAdmin', fn ($user) => $user?->isAdmin());
        Gate::define('manageAdminSensitive', fn ($user) => $user?->isAdmin());

        Event::listen(Login::class, LogSuccessfulLogin::class);
        Event::listen(Registered::class, SendWelcomeNotification::class);

        Event::listen(MessageReceived::class, [AutomationTriggerListener::class, 'handleMessageReceived']);
        Event::listen(MessageReceived::class, [AutoReplyListener::class, 'handle']);
        Event::listen(ContactCreated::class, [AutomationTriggerListener::class, 'handleContactCreated']);
        Event::listen(AutomationWebhookReceived::class, [AutomationTriggerListener::class, 'handleAutomationWebhookReceived']);
        Event::listen(CommerceEventReceived::class, [AutomationTriggerListener::class, 'handleCommerceEvent']);
        Event::listen(WhatsappFlowSubmitted::class, WhatsappFlowSubmissionListener::class);
        // ⚠️ Previously registered ONLY via Laravel's event auto-discovery — a
        // public handleCampaignCompleted(CampaignCompleted $event) method in
        // app/Listeners is enough for discovery to wire it on its own, with no
        // entry here. Made explicit so it keeps firing once
        // bootstrap/app.php disables discovery (see the listener-registration
        // audit there): every other app/Listeners method already had an
        // explicit Event::listen() call, which is what made every one of them
        // fire TWICE (once from discovery, once from here) — this was the one
        // exception, registered zero times explicitly.
        Event::listen(CampaignCompleted::class, [AutomationTriggerListener::class, 'handleCampaignCompleted']);

        // ── Outbound webhook event delivery ─────────────────────────────────
        Event::listen(ContactCreated::class, [DispatchOutboundWebhookListener::class, 'handleContactCreated']);
        Event::listen(MessageReceived::class, [DispatchOutboundWebhookListener::class, 'handleMessageReceived']);

        // ⚠️ Smart QR attribution (§9). A FIFTH OBSERVER on the existing event —
        // not a parallel inbound flow, which CLAUDE.md forbids. Nothing in the
        // driver, controller or routes changed.
        //
        // Synchronous, like the four beside it, and that is load-bearing:
        // WhatsappDriver wraps the inbound persist in WorkspaceContext::for(),
        // so a synchronous listener inherits the correct tenant. A queued one
        // would run outside it, where every scoped read fails closed.
        Event::listen(MessageReceived::class, [RecordQrAttributionListener::class, 'handle']);
        Event::listen(CampaignCompleted::class, [DispatchOutboundWebhookListener::class, 'handleCampaignCompleted']);

        // ── Notification bridging listeners ──────────────────────────────────
        Event::listen(MessageReceived::class, SendNewMessageNotification::class);
        Event::listen(CampaignCompleted::class, SendCampaignCompletedNotification::class);
        Event::listen(AutomationFailed::class, SendAutomationFailedNotification::class);
        Event::listen(ConversationAssigned::class, SendConversationAssignedNotification::class);

        // ── Subscription & billing notifications ────────────────────────────
        Event::listen(SubscriptionStarted::class, SendSubscriptionStartedNotification::class);
        Event::listen(SubscriptionCancelled::class, SendSubscriptionCancelledNotification::class);
        Event::listen(SubscriptionRenewed::class, SendSubscriptionRenewedNotification::class);
        Event::listen(SubscriptionExpired::class, SendSubscriptionExpiredNotification::class);
        Event::listen(PlanChanged::class, SendPlanChangedNotification::class);
        Event::listen(TrialEnding::class, SendTrialEndingNotification::class);

        // ── Named rate limiters ─────────────────────────────────────────────
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)
                ->by(optional($request->user())->id ?: $request->ip());
        });

        RateLimiter::for('webhooks', function (Request $request) {
            // Use the real client IP (respects X-Forwarded-For when trusted proxies are set).
            // Limit is intentionally high: a single Meta app services multiple workspaces and
            // all their traffic arrives from a small pool of Meta egress IPs.
            return Limit::perMinute(1000)->by($request->getClientIp());
        });

        // Public form POSTs are lead-capture, not a shared physical QR scan:
        // repeat attempts from one address are a useful abuse signal.
        RateLimiter::for('flow-form-submissions', function (Request $request) {
            return Limit::perMinute(10)->by('flow-web-form:'.$request->ip());
        });

        RateLimiter::for('ai-runs', function (Request $request) {
            // Two bugs lived here (plan §G-2):
            //
            // 1. `current_workspace_id` does not exist, so $workspaceId was
            //    ALWAYS the client IP. Workspace::find('203.0.113.4') returned
            //    null, so every customer silently got the default 10/min
            //    regardless of the plan they had paid for, and the bucket was
            //    keyed per-IP so users behind one NAT shared a limit.
            //
            // 2. `with('client.activePlan')` is not a valid eager load —
            //    Client::activePlan() is a METHOD returning ?Plan, not a
            //    relation. It never threw only because find() returned null, so
            //    Laravel skipped eager loading entirely. Fixing (1) would have
            //    surfaced a RelationNotFoundException on the first real hit.
            $workspaceId = WorkspaceContext::id();

            $workspace = $workspaceId !== null
                ? Workspace::with('client')->find($workspaceId)
                : null;

            // instanceof rather than nullsafe chaining: BelongsTo is not
            // generically typed here, so static analysis sees Model and cannot
            // resolve Client::activePlan().
            $client = $workspace?->client;
            $plan = $client instanceof Client ? $client->activePlan() : null;

            // data_get rather than array access: Plan::$limits is an Eloquent
            // `array` cast, which static analysis sees as the raw string|null
            // column type.
            $perMinute = (int) (data_get($plan, 'limits.ai_runs_per_minute') ?? 10);

            // Namespaced so a workspace id can never collide with an IP.
            $key = $workspaceId !== null ? 'ws:'.$workspaceId : 'ip:'.$request->ip();

            return Limit::perMinute($perMinute)->by($key);
        });

        // ── Scramble / OpenAPI ──────────────────────────────────────────────
        Scramble::configure()
            ->withDocumentTransformers(function (OpenApi $openApi) {
                $openApi->secure(
                    SecurityScheme::http('bearer')
                );
            });

        // Only include /api/v1/* routes in the spec
        Scramble::routes(function (Route $route) {
            return str_starts_with($route->uri(), 'api/v1');
        });

        Vite::prefetch(concurrency: 3);
    }

    /**
     * Point Guzzle at a valid CA bundle when php.ini references a missing file
     * (Windows cURL error 77), or optionally disable verify for local dev only.
     */
    /** Meta webhook callbacks must use HTTPS in production. */
    private function forceHttpsForWebhookUrls(): void
    {
        $appUrl = config('app.url');
        if (
            app()->environment('production')
            && is_string($appUrl)
            && str_starts_with($appUrl, 'https://')
        ) {
            URL::forceScheme('https');
        }
    }

    /**
     * Keep query-string credentials out of connection-failure messages.
     *
     * Several providers authenticate with a query parameter rather than a header,
     * and Guzzle appends the full URI to every ConnectException message. Those
     * messages are written to logs, to `failed_jobs`, to `lead_scrape_jobs.error`
     * and to HTTP responses. Registered globally rather than per call site so a
     * new `Http::get($url, ['key' => …])` is covered the day it is written.
     *
     * See BUG-005 and BUG-006 in docs/found-bugs.md.
     */
    /**
     * Phase 0, slice 4. Clear tenant context at every process boundary.
     *
     * `WorkspaceContext` memoises its resolution per user id for the lifetime of
     * the PHP process. That is correct for a web request, which handles one
     * user and exits. It is wrong for every long-lived process:
     *
     *   - a queue worker handles many tenants' jobs in sequence;
     *   - `schedule:run` executes many commands in one process;
     *   - Octane (not installed today) would persist it across REQUESTS, which
     *     is the same leak in the request path and considerably worse.
     *
     * ─── Why BEFORE and not after ───────────────────────────────────────────
     *
     * `Queue::before` rather than `Queue::after`, deliberately. An after-hook
     * that does not run — a fatal error, a killed worker, `SIGKILL` mid-job —
     * leaves the process dirty and the NEXT job inherits stale context. A
     * before-hook that does not run means the job never started. Cleaning up on
     * entry is the only version that is safe against the failures you cannot
     * catch.
     *
     * `Queue::failing` and `Queue::exceptionOccurred` are registered too, so the
     * cleanup is not deferred to whenever the next job happens to arrive.
     *
     * If Octane is ever adopted, add its `RequestReceived` listener here. This
     * method is the single place that knows about process boundaries — that is
     * the point of consolidating it.
     */
    private function flushWorkspaceContextBetweenProcesses(): void
    {
        $flush = static fn () => WorkspaceContext::flushBetweenUnitsOfWork();

        Queue::before($flush);
        Queue::failing($flush);
        Queue::exceptionOccurred($flush);

        Event::listen(CommandStarting::class, $flush);
    }

    private function scrubCredentialsFromConnectionFailures(): void
    {
        Http::globalMiddleware(ConnectionExceptionScrubber::middleware());
    }

    private function configureHttpClientSsl(): void
    {
        $caPath = config('http.ca_path');

        if (is_string($caPath) && $caPath !== '' && is_file($caPath)) {
            Http::globalOptions(['verify' => $caPath]);

            return;
        }

        // Laragon / Windows PHP builds: cacert.pem next to php.exe (php.ini may still point elsewhere).
        if (defined('PHP_BINARY') && is_string(PHP_BINARY) && PHP_BINARY !== '') {
            $phpDirBundle = dirname(PHP_BINARY).DIRECTORY_SEPARATOR.'extras'
                .DIRECTORY_SEPARATOR.'ssl'.DIRECTORY_SEPARATOR.'cacert.pem';
            if (is_file($phpDirBundle)) {
                Http::globalOptions(['verify' => $phpDirBundle]);

                return;
            }
        }

        if (config('http.verify_ssl') === false) {
            Http::globalOptions(['verify' => false]);
        }
    }
}
