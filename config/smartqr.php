<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Attribution session lifetime
    |--------------------------------------------------------------------------
    |
    | How long a scan's reference token stays valid, in minutes.
    |
    | ⚠️ In config rather than a constant, deliberately: the right value is an
    | empirical question about how long customers take between scanning at a
    | counter and actually typing, and it should be tunable when real data shows
    | the distribution — without a deploy.
    |
    | 30 minutes is the ruled default. A customer who scans, sits down and then
    | types is the normal case and must stay attributed. A token screenshotted
    | and shared an hour later is not attribution, it is noise.
    |
    */
    'attribution_ttl_minutes' => (int) env('SMART_QR_ATTRIBUTION_TTL_MINUTES', 30),

    /*
    |--------------------------------------------------------------------------
    | Raw scan retention
    |--------------------------------------------------------------------------
    |
    | How many days of individual `smart_qr_scan_events` rows are kept. Daily
    | aggregates are kept INDEFINITELY — see R-4's amendment, which narrows the
    | reassignment guarantee to "the previous tenant's AGGREGATES stay
    | reachable", not their raw scans.
    |
    | ⚠️ THIS VALUE IS READ BY TWO COMMANDS THAT MUST AGREE.
    |
    | `smartqr:prune-scans` deletes raw rows older than this. `smartqr:aggregate`
    | REFUSES to recompute a day older than this — because recomputing a pruned
    | day would produce zero and overwrite a correct historical aggregate.
    |
    | Changing it changes both. Lowering it makes the prune delete more on its
    | next run; raising it does NOT restore what was already deleted.
    |
    */
    'scan_retention_days' => (int) env('SMART_QR_SCAN_RETENTION_DAYS', 90),

    /*
     * How long a built export ZIP stays on disk.
     *
     * ⚠️ MUCH SHORTER THAN scan_retention_days, and deliberately: a scan is
     * customer data that reports depend on, while an export is a rebuildable
     * convenience file. 58 archives had accumulated to 170 MB in development
     * with nothing ever deleting them.
     */
    'export_retention_days' => (int) env('SMART_QR_EXPORT_RETENTION_DAYS', 7),

];
