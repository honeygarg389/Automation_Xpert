<?php

namespace App\Modules\Social\Support;

/**
 * ═══ WHAT OUR DRIVERS ACTUALLY DELIVER TODAY ════════════════════════════════
 *
 * ⚠️ THIS IS DELIBERATELY NARROWER THAN {@see NetworkCapabilities}, AND THE GAP
 * IS THE WHOLE POINT. Two "capability" concepts coexist on purpose:
 *
 *   NetworkCapabilities  — what the PLATFORM's API accepts. Sourced from vendor
 *                          documentation. Changes when Meta or LinkedIn change
 *                          their docs. Nobody here controls it.
 *   DriverCapabilities   — what OUR CODE in app/Modules/Social/Services/Drivers
 *                          actually sends. Changes when WE write code. Entirely
 *                          within our control.
 *
 * Collapsing them into one map would force a false choice. Claim the platform's
 * capability and the composer offers a carousel to Instagram that the driver
 * publishes as a single image — silently, because dropping media is not an API
 * error. Claim only the driver's and the vendor research is lost, along with the
 * numeric ranges (carousel counts, ratios) that are still correct and still
 * needed for the accounts where the feature does work.
 *
 * So: DriverCapabilities gates WHETHER a post type is possible at all.
 * NetworkCapabilities still supplies HOW MANY and WHAT SHAPE once it is.
 *
 * ─── ⚠️ EVERY `false` BELOW IS A TODO, NOT A PLATFORM LIMIT ─────────────────
 *
 * Each entry cites the exact line of driver code that makes it false. All five
 * networks accept far more than we send. When Branch 4 (existing-platform
 * video/carousel work) adds real driver support, THIS FILE MUST BE UPDATED IN
 * THE SAME COMMIT as the driver change — a driver that gains video while this
 * map still says false will have the composer refuse to offer it, and the
 * symptom ("the feature shipped but nobody can select it") points at the UI
 * rather than here.
 *
 * The guard test in tests/Feature/Social/PostTypeValidationTest.php pins these
 * values so the update cannot be forgotten quietly.
 */
final class DriverCapabilities
{
    /**
     * Keyed by the same network slugs as NetworkCapabilities.
     *
     * @var array<string, array{image: bool, carousel: bool, video: bool, why: string}>
     */
    public const IMPLEMENTED = [

        NetworkCapabilities::FACEBOOK => [
            'image' => true,
            'carousel' => true,
            'video' => false,
            // FacebookDriver::publish() posts a single photo to /photos, and for
            // count($mediaUrls) > 1 uploads each as published=false then attaches
            // them to one /feed post. There is no /videos call anywhere.
            'why' => 'photos + attached_media implemented; no video endpoint',
        ],

        NetworkCapabilities::INSTAGRAM => [
            'image' => true,
            // ⚠️ The PLATFORM supports carousels (NetworkCapabilities says 2-10).
            // InstagramSocialDriver::createContainer() sends
            // 'image_url' => $mediaUrls[0] and nothing else, so images 2..n are
            // discarded with no error and no failed row.
            'carousel' => false,
            'video' => false,
            'why' => 'createContainer() sends image_url => mediaUrls[0] only',
        ],

        NetworkCapabilities::LINKEDIN => [
            // ⚠️ LinkedInDriver::publish() hardcodes
            // 'shareMediaCategory' => 'NONE' and never reads media_urls at all.
            // Every attachment is dropped.
            'image' => false,
            'carousel' => false,
            'video' => false,
            'why' => "publish() hardcodes shareMediaCategory => 'NONE'",
        ],

        NetworkCapabilities::TWITTER => [
            // TwitterDriver::publish() POSTs only {text: ...} to /2/tweets.
            // Media upload requires the v1.1 media/upload chain, unimplemented.
            'image' => false,
            'carousel' => false,
            'video' => false,
            'why' => 'publish() sends text only; no media/upload chain',
        ],

        NetworkCapabilities::YOUTUBE => [
            // Video-only by nature, and the only driver that genuinely uploads
            // video (resumable upload to /upload/youtube/v3/videos).
            'image' => false,
            'carousel' => false,
            'video' => true,
            'why' => 'resumable video upload implemented; images are meaningless',
        ],
    ];

    /** Post types a post may declare. Mirrors the social_media_posts.post_type enum. */
    public const TYPE_IMAGE = 'image';

    public const TYPE_VIDEO = 'video';

    public const TYPE_TEXT = 'text';

    /** @var list<string> */
    public const POST_TYPES = [self::TYPE_IMAGE, self::TYPE_VIDEO, self::TYPE_TEXT];

    /** Media shapes, meaningful only when post_type = image. */
    public const MEDIA_SINGLE = 'single';

    public const MEDIA_CAROUSEL = 'carousel';

    /** @var list<string> */
    public const MEDIA_TYPES = [self::MEDIA_SINGLE, self::MEDIA_CAROUSEL];

    /**
     * Unknown network => false. Fail closed: a network we have never heard of
     * cannot be proven to deliver anything.
     */
    public static function supports(string $network, string $capability): bool
    {
        return (bool) (self::IMPLEMENTED[$network][$capability] ?? false);
    }

    /** Why a capability is unavailable, for error messages. Empty when unknown. */
    public static function reason(string $network): string
    {
        return self::IMPLEMENTED[$network]['why'] ?? 'no driver';
    }

    /**
     * The capability a (post_type, media_type) pair requires.
     *
     * text requires nothing — it is deliverable everywhere, which is why AI
     * planner posts default to it.
     */
    public static function requiredCapability(string $postType, ?string $mediaType): ?string
    {
        if ($postType === self::TYPE_VIDEO) {
            return 'video';
        }

        if ($postType === self::TYPE_IMAGE) {
            return $mediaType === self::MEDIA_CAROUSEL ? 'carousel' : 'image';
        }

        return null;
    }

    /**
     * Networks that can actually deliver the given post shape.
     *
     * @return list<string>
     */
    public static function eligibleNetworks(string $postType, ?string $mediaType = null): array
    {
        $capability = self::requiredCapability($postType, $mediaType);

        if ($capability === null) {
            return NetworkCapabilities::DRIVER_BACKED;
        }

        return array_values(array_filter(
            NetworkCapabilities::DRIVER_BACKED,
            fn (string $n) => self::supports($n, $capability)
        ));
    }

    /**
     * The payload handed to the frontend, driver-backed networks only — same
     * restriction and same reason as NetworkCapabilities::forFrontend().
     *
     * @return array<string, array{image: bool, carousel: bool, video: bool, why: string}>
     */
    public static function forFrontend(): array
    {
        $out = [];
        foreach (NetworkCapabilities::DRIVER_BACKED as $network) {
            $out[$network] = self::IMPLEMENTED[$network];
        }

        return $out;
    }
}
