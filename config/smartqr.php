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

];
