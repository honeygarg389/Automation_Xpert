<?php

use App\Http\Controllers\Admin\Auth\AdminLoginController;
use App\Http\Controllers\Admin\ImpersonationController;
use App\Http\Controllers\Install\InstallController;
use App\Http\Middleware\BroadcastingAuthDebug;
use App\Http\Middleware\CheckApiAbility;
use App\Http\Middleware\EnforceLimit;
use App\Http\Middleware\EnsureAdminRole;
use App\Http\Middleware\EnsureClientScope;
use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\EnsureNotDemoMode;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\EnsureUserRole;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RedirectIfAdminAuthenticated;
use App\Http\Middleware\RequestIdMiddleware;
use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\SecureHeaders;
use App\Http\Middleware\SetLocale;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Sentry\Laravel\Integration;
use Sentry\State\Scope;

return Application::configure(basePath: dirname(__DIR__))
    // ⚠️ Event auto-discovery is DISABLED, deliberately and durably.
    //
    // Laravel's default (`Application::configure()` calls `->withEvents()`
    // with `discover: true` even when bootstrap/app.php never calls it
    // itself) scans app/Listeners and auto-registers every public
    // `handle*`/`__invoke` method whose first parameter is a class. Every
    // listener in app/Listeners ALSO has an explicit `Event::listen(...)`
    // call in AppServiceProvider::boot() — so every one of them fired TWICE
    // for every real event: two automation runs, two outbound webhook jobs,
    // two audit-log rows per login, etc. Confirmed via
    // `php artisan event:list` (each app/Listeners entry appeared twice) and
    // via `app('events')->getListeners(...)` returning 2x the expected count
    // per test-suite run.
    //
    // Before disabling, every listener method in app/Listeners was inventoried
    // against AppServiceProvider::boot()'s explicit registrations. ONE method
    // — AutomationTriggerListener::handleCampaignCompleted — had no explicit
    // registration and relied on discovery alone; it now has one, added in
    // the same commit as this line, so nothing loses its only wiring.
    // Listeners under app/Modules/*/Listeners (RecordQrAttributionListener,
    // InvalidateEntitlementCache) were never inside the discovery path
    // (`app/Listeners` only) and are unaffected either way.
    ->withEvents(discover: false)
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        then: function () {
            // Web setup wizard (guest). Reachable on a fresh deploy until the app
            // is marked installed; EnsureInstalled redirects everything else here.
            Route::middleware(['web'])
                ->prefix('install')
                ->name('install.')
                ->group(function () {
                    Route::get('/', [InstallController::class, 'show'])->name('show');
                    Route::post('test-database', [InstallController::class, 'testDatabase'])->name('test-database');
                    Route::post('/', [InstallController::class, 'run'])->name('run');
                });

            // Webhook intake routes (no CSRF, no auth – signature-verified inside controllers)
            Route::middleware(['web'])
                ->group(base_path('routes/webhooks.php'));

            Route::middleware(['web', 'auth', 'role:client', 'client.scope', 'demo'])
                ->prefix('app')
                ->name('client.')
                ->group(base_path('routes/client.php'));

            // Reports & CSV exports
            Route::middleware(['auth', 'role:client', 'client.scope'])
                ->group(base_path('routes/reports.php'));

            // Admin sign-in is unified onto the main /login page (the
            // AuthenticatedSessionController tries the admin guard first). We
            // keep the `admin.login` route name so the many existing redirects
            // to it (RequirePermission, install flow, admin logout, the
            // auth-exception handler) still resolve — it now just forwards to
            // /login. `redirect.if.admin` still bounces an already-signed-in
            // admin to the dashboard.
            Route::middleware(['web', 'redirect.if.admin'])
                ->get('admin/login', fn () => redirect()->route('login'))
                ->name('admin.login');

            // Admin logout (authenticated admin only)
            Route::post('admin/logout', [AdminLoginController::class, 'destroy'])
                ->middleware(['web', 'auth:admin'])
                ->name('admin.logout');

            // Impersonation stop: callable by impersonated user (web guard), no admin auth required
            Route::post('admin/impersonation/stop', [ImpersonationController::class, 'stop'])
                ->middleware(['web', 'auth'])
                ->name('admin.impersonation.stop');

            // Admin panel (authenticated admin only; RBAC applied per-route).
            Route::middleware(['web', 'auth:admin', 'demo'])
                ->prefix('admin')
                ->name('admin.')
                ->group(base_path('routes/admin.php'));
        }
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            // Runs before the DB-querying middleware below so a fresh deploy is
            // redirected to /install without touching the (empty) database.
            EnsureInstalled::class,
            AddLinkHeadersForPreloadedAssets::class,
            SetLocale::class,
            HandleInertiaRequests::class,
            SecureHeaders::class,
            RequestIdMiddleware::class,
            // Diagnostics for Pusher's auth POST. Read-only; always passes.
            BroadcastingAuthDebug::class,
        ]);
        $middleware->alias([
            'demo' => EnsureNotDemoMode::class,
            'admin' => EnsureAdminRole::class,
            'admin.super' => EnsureSuperAdmin::class,
            'role' => EnsureUserRole::class,
            'permission' => RequirePermission::class,
            'redirect.if.admin' => RedirectIfAdminAuthenticated::class,
            'client.scope' => EnsureClientScope::class,
            'limit' => EnforceLimit::class,
            'api.ability' => CheckApiAbility::class,
            // SEC-006: refuse any token whose user has been deactivated.
            'user.active' => EnsureUserIsActive::class,
        ]);
        // Shared middleware stack for all client module routes (mirrors routes/client.php).
        $middleware->appendToGroup('client-app', [
            'auth',
            'verified',
            'role:client',
            EnsureClientScope::class,
            EnsureNotDemoMode::class,
        ]);
        // Trust all proxies so X-Forwarded-For is used for real client IPs.
        // In production, restrict to your actual load balancer IPs via TRUSTED_PROXIES env var.
        $middleware->trustProxies(
            at: env('TRUSTED_PROXIES', '*'),
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

        // ⚠️ EVERY billing gateway with a webhook route needs an entry here.
        // These routes carry the `web` group, so anything absent from this list is
        // CSRF-protected and answers a real gateway callback with 419 — silently,
        // because the gateway retries into the same rejection and nothing is logged
        // on our side. Razorpay and Cashfree were missing and were unreachable.
        // Paddle's entry was removed with the gateway itself.
        $middleware->validateCsrfTokens(except: [
            'webhooks/stripe',
            'webhooks/paypal',
            'webhooks/razorpay',
            'webhooks/cashfree',
            'webhooks/whatsapp/*',
            'webhooks/meta/*',
            'webhooks/sms/*',
            'webhooks/automation/*',
            'webhooks/ecommerce/*',
            // Phase 1B: one central Petpooja ingress URL, no dynamic segment —
            // a literal path, not a wildcard, since there is exactly one route.
            'webhooks/pos/petpooja',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // ⚠️ NEVER FLASH CREDENTIALS BACK INTO THE SESSION.
        //
        // On a validation failure Laravel calls
        //   withInput(Arr::except($request->input(), $dontFlash))
        // and the framework default covers only password fields. Everything else
        // the user typed is written to the session store — which here is the
        // `sessions` TABLE with `session.encrypt = false`, so the payload is
        // base64 of serialized PHP and trivially recoverable.
        //
        // A pasted Meta system-user token is a long-lived credential for the
        // customer's entire WhatsApp Business Account. One mistyped field length
        // was enough to persist it in the clear.
        //
        // The identifiers travel with it: they are not secret on their own, but
        // together they are the whole connection, and there is no reason to keep
        // any of it after the request fails.
        $exceptions->dontFlash([
            'system_user_token',
            'access_token',
            'waba_id',
            'phone_number_id',
            'app_id',
        ]);

        $exceptions->reportable(function (Throwable $e) {
            Log::channel('errors')->error($e->getMessage(), [
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        });

        // Optional Sentry integration — only active when SENTRY_LARAVEL_DSN is set
        if (config('sentry.dsn')) {
            $exceptions->reportable(function (Throwable $e) {
                if (function_exists('\Sentry\configureScope')) {
                    \Sentry\configureScope(function (Scope $scope) {
                        $user = auth()->user();
                        if ($user) {
                            $scope->setTag('workspace_id', (string) ($user->current_workspace_id ?? $user->workspace_id ?? 'unknown'));
                            $scope->setUser(['id' => $user->id, 'email' => $user->email]);
                        }
                    });
                }
                Integration::captureUnhandledException($e);
            });
        }

        $exceptions->renderable(function (AuthenticationException $e, $request) {
            if (in_array('admin', $e->guards(), true)) {
                return $request->expectsJson()
                    ? response()->json(['message' => 'Unauthenticated.'], 401)
                    : redirect()->route('admin.login');
            }

            return $request->expectsJson()
                ? response()->json(['message' => 'Unauthenticated.'], 401)
                : redirect()->route('login');
        });
    })->create();
