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

// ── Petpooja POS order-processing safety net (Phase 2 slice 2) ──────────────
//
// NOT the primary path — see SweepStalledPosWebhookEventsCommand's docblock.
// Every 5 minutes is frequent enough to keep a real stall short-lived without
// competing with the job's own retry backoff (the command's own 10-minute
// default age threshold is what actually prevents redundant dispatches, not
// this interval).
Schedule::command('restaurant:sweep-stalled-webhook-events')
    ->everyFiveMinutes()
    ->name('restaurant-sweep-stalled-webhook-events')
    ->withoutOverlapping()
    ->onOneServer();

// A stale `sending` row crossed the provider boundary, so it is terminally
// unknown rather than re-dispatched. This command performs no send itself.
Schedule::command('restaurant:mark-stalled-digital-bill-deliveries-unknown')
    ->everyFiveMinutes()
    ->name('restaurant-mark-stalled-digital-bill-deliveries-unknown')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('restaurant:dispatch-due-feedback-requests')
    ->everyFiveMinutes()
    ->name('restaurant-dispatch-due-feedback-requests')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('restaurant:mark-stalled-feedback-requests-unknown')
    ->everyFiveMinutes()
    ->name('restaurant-mark-stalled-feedback-requests-unknown')
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

// ── Smart QR export archive retention ───────────────────────────────────────
//
// ⚠️ Deletes FILES, not customer data — an export is a rebuildable convenience
// archive, which is why its window is 7 days against the scans' 90. Nothing had
// ever deleted these: 58 archives totalling 170 MB had accumulated in
// development, and the only thing bounding the list was a display cap that hid
// them rather than reclaiming them.
//
// ⚠️ Archives with a smart_qr_exports row are EXPIRED rather than deleted
// outright — the file goes, the row stays. See the command.
//
// Weekly, on the same day as the scan prune but an hour later, so two
// destructive jobs never overlap and a log reader sees them in a fixed order.
Schedule::command('smartqr:prune-exports')
    ->weeklyOn(0, '04:00')
    ->name('smartqr-prune-exports')
    ->withoutOverlapping()
    ->onOneServer();

// ── Database backup ─────────────────────────────────────────────────────────
//
// ⚠️ NOTHING RAN THIS UNTIL NOW. `db:backup` has existed and been practised by
// hand since 2026-08-07, and `db:restore` was round-trip tested against it —
// but the command was in no schedule, so on any unattended box the recovery
// story was "a working tool and no backups".
//
// 01:30 is chosen, not arbitrary:
//   - every :00 is occupied by five hourly billing jobs;
//   - it follows smartqr:aggregate (00:20), so the dump is taken after the
//     night's aggregation rather than during it;
//   - it PRECEDES both destructive weekly prunes (03:00 scans, 04:00 exports),
//     so a backup always exists before anything deletes rows or files.
//
// ⚠️ No retention exists yet — see the note in docs/deployment-safety.md. The
// dumps accumulate in `backups/` on the configured disk and nothing removes
// them. A prune must not simply delete by age: db:restore calls db:backup for
// its pre-restore SAFETY backup, which lands in the same directory and is the
// one archive that must never be reaped.
Schedule::command('db:backup')
    ->dailyAt('01:30')
    ->name('db-backup')
    ->withoutOverlapping()
    ->onOneServer();
