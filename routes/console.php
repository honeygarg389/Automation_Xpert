<?php

use App\Http\Controllers\Admin\CronSetupController;
use App\Modules\Broadcasting\Jobs\LaunchScheduledCampaignsJob;
use App\Modules\Broadcasting\Models\UsageMeter;
use App\Modules\Social\Jobs\DispatchScheduledPostsJob;
use App\Modules\Social\Jobs\RefreshSocialTokensJob;
use App\Modules\Whatsapp\Jobs\TemplateSyncJob;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use App\Services\WebhookIdempotencyService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Heartbeat: records the last time the scheduler ran so the admin "Cron Setup"
// guide can confirm the server's cron entry is actually firing.
Schedule::call(function () {
    Cache::put(CronSetupController::HEARTBEAT_KEY, now()->toIso8601String(), now()->addDay());
})->everyMinute()->name('scheduler-heartbeat');

// ─── Marketing Suite Scheduled Tasks ────────────────────────────────────────

// Dispatch any campaigns scheduled for now
Schedule::job(new LaunchScheduledCampaignsJob, 'broadcast')
    ->everyMinute()
    ->name('launch-scheduled-campaigns')
    ->withoutOverlapping();

// Sync WhatsApp templates from Meta (once per day)
Schedule::call(function () {
    WhatsappBusinessAccount::all()->each(function ($waba) {
        TemplateSyncJob::dispatch($waba->id)->onQueue('whatsapp');
    });
})->daily()->name('sync-whatsapp-templates');

// Dispatch scheduled social posts (every minute)
Schedule::job(new DispatchScheduledPostsJob, 'social')
    ->everyMinute()
    ->name('dispatch-social-posts')
    ->withoutOverlapping();

// Refresh expiring social OAuth tokens daily
Schedule::job(new RefreshSocialTokensJob, 'social')
    ->dailyAt('02:00')
    ->name('refresh-social-tokens');

// Reset monthly usage meters on the 1st of each month
Schedule::call(function () {
    // Meters older than 2 months are pruned; current month is always kept
    UsageMeter::where('period', '<', (int) now()->subMonths(2)->format('Ym'))->delete();
})->monthlyOn(1, '00:05')->name('reset-usage-meters');

// Prune inbound webhook idempotency records older than 30 days
Schedule::call(function () {
    app(WebhookIdempotencyService::class)->prune(30);
})->weekly()->name('prune-inbound-webhook-events');

// Sync subscription statuses with payment gateways (hourly)
Schedule::command('billing:sync')
    ->hourly()
    ->name('billing-sync')
    ->withoutOverlapping()
    ->onOneServer();

// Expire trials that have passed their trial_ends_at and not yet converted
Schedule::command('billing:expire-trials')
    ->hourly()
    ->name('billing-expire-trials')
    ->withoutOverlapping()
    ->onOneServer();

// Bill Tap subscriptions due for renewal (Tap has no hosted auto-renew; merchant-initiated)
Schedule::command('billing:charge-recurring')
    ->hourly()
    ->name('billing-charge-recurring-tap')
    ->withoutOverlapping()
    ->onOneServer();

// Bill Paymob subscriptions due for renewal (MIT save-card pattern)
Schedule::command('billing:charge-recurring-paymob')
    ->hourly()
    ->name('billing-charge-recurring-paymob')
    ->withoutOverlapping()
    ->onOneServer();

// Bill MyFatoorah subscriptions due for renewal (MIT save-token pattern)
Schedule::command('billing:charge-recurring-myfatoorah')
    ->hourly()
    ->name('billing-charge-recurring-myfatoorah')
    ->withoutOverlapping()
    ->onOneServer();

// Notify users whose trial ends in 3 days (daily at 09:00)
Schedule::command('notifications:trial-ending --days=3')
    ->dailyAt('09:00')
    ->name('notify-trial-ending-3d')
    ->withoutOverlapping()
    ->onOneServer();

// Send weekly performance digest to all workspace owners (Monday 09:00)
Schedule::command('reports:weekly-digest')
    ->mondays()
    ->at('09:00')
    ->name('weekly-digest-emails')
    ->withoutOverlapping()
    ->onOneServer();

// ── Smart QR daily aggregates (§10) ─────────────────────────────────────────
//
// ⚠️ Builds YESTERDAY, not today. Today is still accumulating and the dashboard
// computes the current day live from raw rows, so it never depends on this job
// having run.
//
// --days=3 rather than 1: a re-run is idempotent (updateOrCreate against the
// unique assignment+date grain), so overlapping the last three days repairs any
// gap left by a missed run or a late-arriving scan, at no cost.
Schedule::command('smartqr:aggregate --days=3')
    ->dailyAt('00:20')
    ->name('smartqr-daily-aggregates')
    ->withoutOverlapping()
    ->onOneServer();

// ── Smart QR raw scan retention (R-4 amendment) ─────────────────────────────
//
// ⚠️ DELETES CUSTOMER DATA, and it runs AFTER the aggregator by design — GUARD 2
// refuses any day with no aggregate row, so ordering these the other way round
// would make the prune refuse every night and quietly never run.
//
// Weekly rather than daily: there is no urgency, and a smaller number of larger
// batched runs is easier to notice in a log than a nightly delete nobody reads.
Schedule::command('smartqr:prune-scans')
    ->weeklyOn(0, '03:00')
    ->name('smartqr-prune-scans')
    ->withoutOverlapping()
    ->onOneServer();
