<?php

return [

    'instagram' => [

        /*
        |---------------------------------------------------------------------
        | Media container polling
        |---------------------------------------------------------------------
        |
        | Instagram publishes in two calls — create a media container, then
        | publish it — and Meta processes the image asynchronously in between.
        | The driver polls `status_code` until FINISHED.
        |
        | ⚠️ THE PRODUCT OF THESE TWO MUST STAY WELL UNDER
        | PublishSocialPostJob::$timeout (120s). 3s x 15 leaves ~45s of waiting
        | and lands near 60s of wall clock once the status round trips and the
        | two POSTs are counted. Widening them risks the worker killing the job
        | MID-POLL, which is strictly worse than giving up cleanly: a killed job
        | records nothing, so the retry cannot resume the container it created.
        |
        | A genuinely slow upload is covered by the job's retry schedule
        | ([30, 120, 300] seconds, jittered), not by a longer loop here.
        |
        | The interval is also what the test suite sets to 0 — without that,
        | exercising the exhaustion path would sleep for 45 real seconds.
        */
        'poll_interval_seconds' => env('INSTAGRAM_POLL_INTERVAL_SECONDS', 3),

        'poll_max_attempts' => env('INSTAGRAM_POLL_MAX_ATTEMPTS', 15),

        /*
        | ⚠️ VIDEO (REELS) GETS A WIDER BUDGET — Meta's own current guidance is
        | to poll "once per minute, for no more than 5 minutes", and separately
        | that video containers commonly take 30s to several minutes. Neither
        | number fits inside PublishSocialPostJob::$timeout (120s), and there is
        | no official "3s/90s" sample to match — that figure does not appear in
        | current Meta documentation.
        |
        | So this does NOT try to reach Meta's stated ceiling. It reuses the
        | SAME architecture as images: a bounded in-request poll, with the job's
        | own retry schedule ([30, 120, 300]s, jittered) covering anything
        | slower than the window — a video simply gets a wider window than an
        | image because it genuinely needs one.
        |
        | 3s x 20 attempts = ~57s of sleeping. With ~22 round trips (create +
        | 20 status GETs + publish) at roughly 1s overhead each, worst case
        | lands near 79s — a 41s margin (34%) under the 120s timeout, the same
        | proportion of headroom the image budget already keeps.
        */
        'video_poll_max_attempts' => env('INSTAGRAM_VIDEO_POLL_MAX_ATTEMPTS', 20),

        /*
        | Meta expires an unpublished container after 24 hours. A stored id
        | older than this is discarded and a fresh container created, rather
        | than polled to a conclusion its age already gives.
        */
        'container_ttl_hours' => env('INSTAGRAM_CONTAINER_TTL_HOURS', 24),
    ],

];
