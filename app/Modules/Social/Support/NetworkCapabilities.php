<?php

namespace App\Modules\Social\Support;

/**
 * ═══ WHAT EACH SOCIAL NETWORK ACCEPTS, IN ONE PLACE ═════════════════════════
 *
 * Before this existed, a network's capabilities were knowable only by READING
 * DRIVER CODE — InstagramSocialDriver throws "Instagram posts require at least
 * one image", YoutubeDriver is video-only, and nothing said so anywhere the UI
 * could see. The composer therefore offered every connected account for every
 * kind of post and let the platform refuse afterwards.
 *
 * The character limit was worse: the SAME map was written out FOUR times — once
 * in PHP for the AI prompt and three times in JSX (Composer, Edit, AiPlanner).
 * Removing TikTok meant editing all four, and nothing would have failed if one
 * had been missed. That is the "one concept, several definitions" shape this
 * codebase has been bitten by repeatedly.
 *
 * ─── ⚠️ null MEANS "NOT VERIFIED", NEVER "NO LIMIT" ─────────────────────────
 *
 * Every non-null number here was read from the platform's own current developer
 * documentation. Where the docs do not state a value, or state it ambiguously,
 * the field is null and carries a comment saying so. A consumer must treat null
 * as "we do not know — do not enforce", not as "unlimited". Filling one in later
 * is a research task, not a guess.
 *
 * ⚠️ RATIOS ARE width/height AS FLOATS. 9:16 portrait is 0.5625; 16:9 landscape
 * is 1.7778; 1:1 is 1.0; 4:5 is 0.8. Storing them as floats rather than "9:16"
 * strings is what makes a range comparison possible at all.
 */
final class NetworkCapabilities
{
    public const FACEBOOK = 'facebook';

    public const INSTAGRAM = 'instagram';

    public const LINKEDIN = 'linkedin';

    public const TWITTER = 'twitter';

    public const YOUTUBE = 'youtube';

    public const PINTEREST = 'pinterest';

    public const THREADS = 'threads';

    /**
     * Networks with a working driver TODAY. Pinterest and Threads are described
     * below but have no driver, no OAuth flow and no enum value yet — their
     * entries exist so the research is not lost between now and their own build
     * branches, NOT because they can be posted to.
     *
     * @var list<string>
     */
    public const DRIVER_BACKED = [
        self::FACEBOOK,
        self::INSTAGRAM,
        self::LINKEDIN,
        self::TWITTER,
        self::YOUTUBE,
    ];

    /**
     * A carousel is two or more items by definition on every platform that has
     * one, so the intersection starts here rather than at zero.
     */
    public const CAROUSEL_FLOOR = 2;

    /** @var list<string> */
    public const PLANNED = [
        self::PINTEREST,
        self::THREADS,
    ];

    /**
     * @var array<string, array<string, mixed>>
     */
    public const NETWORKS = [

        self::FACEBOOK => [
            'supports_image' => true,
            'supports_video' => true,
            // FacebookDriver already uploads several unpublished photos and
            // attaches them to one feed post, so multi-image works today.
            'supports_carousel' => true,
            'carousel_min' => 2,
            // Not documented by Meta for attached_media; null so nothing
            // enforces a number nobody has verified.
            'carousel_max' => null,
            // Meta documents no aspect-ratio bounds for Page photos.
            'image_ratio_min' => null,
            'image_ratio_max' => null,
            // "The aspect ratio of the video must be between 9x16 and 16x9."
            'video_ratio_min' => 0.5625,   // 9:16
            'video_ratio_max' => 1.7778,   // 16:9
            // Multi-part / URL upload: 20 minutes, 1 GB. Resumable upload allows
            // 45 min / 1.5 GB, but the driver does not use resumable upload.
            'max_video_seconds' => 1200,
            'max_file_bytes' => 1073741824,
            'char_limit' => 63206,
        ],

        self::INSTAGRAM => [
            'supports_image' => true,
            'supports_video' => true,
            'supports_carousel' => true,
            'carousel_min' => 2,
            'carousel_max' => 10,
            // ⚠️ 4:5 to 1.91:1 — a RANGE. It is often quoted as "1:1 and 4:5",
            // which names the lower bound and one interior value and silently
            // drops landscape. 1.91:1 images are accepted and must not be
            // rejected by a picker that only offers two ratios.
            'image_ratio_min' => 0.8,      // 4:5
            'image_ratio_max' => 1.91,     // 1.91:1
            // ⚠️ Reels accept 0.01:1 to 10:1. 9:16 is RECOMMENDED to avoid
            // cropping — it is not a constraint, and enforcing it would reject a
            // great deal of valid content.
            'video_ratio_min' => 0.01,
            'video_ratio_max' => 10.0,
            'max_video_seconds' => null,   // not stated on the publishing docs
            'max_file_bytes' => null,      // not stated on the publishing docs
            'char_limit' => 2200,
        ],

        self::LINKEDIN => [
            'supports_image' => true,
            'supports_video' => true,
            'supports_carousel' => true,
            // MultiImage post: "a minimum of 2 images and maximum of 20 images".
            'carousel_min' => 2,
            'carousel_max' => 20,
            // "The photo's aspect ratio can range from 3:1 to 4:5 (width:height)."
            'image_ratio_min' => 0.8,      // 4:5
            'image_ratio_max' => 3.0,      // 3:1
            'video_ratio_min' => null,     // not researched
            'video_ratio_max' => null,
            'max_video_seconds' => null,   // not researched
            'max_file_bytes' => null,
            'char_limit' => 3000,
        ],

        self::TWITTER => [
            'supports_image' => true,
            'supports_video' => true,
            'supports_carousel' => true,
            'carousel_min' => 2,
            // ⚠️ PROVISIONAL. 4 is the widely-known limit but was NOT confirmed
            // against X's current docs during research.
            'carousel_max' => 4,
            'image_ratio_min' => null,     // not documented
            'image_ratio_max' => null,
            'video_ratio_min' => null,     // not researched
            'video_ratio_max' => null,
            'max_video_seconds' => null,
            'max_file_bytes' => null,
            'char_limit' => 280,
        ],

        self::YOUTUBE => [
            // ⚠️ VIDEO ONLY. YoutubeDriver uploads a video file; there is no
            // image post on YouTube — exactly the kind of fact the composer
            // could not previously know.
            'supports_image' => false,
            'supports_video' => true,
            'supports_carousel' => false,
            'carousel_min' => null,
            'carousel_max' => null,
            'image_ratio_min' => null,
            'image_ratio_max' => null,
            // Ordinary uploads accept effectively any ratio. The 3-minute,
            // square-or-vertical rule decides whether an upload is treated as a
            // SHORT — it is not a validity constraint, so nothing is enforced.
            'video_ratio_min' => null,
            'video_ratio_max' => null,
            'max_video_seconds' => null,
            'max_file_bytes' => null,
            'char_limit' => 5000,          // description limit
        ],

        // ─── Planned: no driver, no OAuth, not in the network enum ──────────

        self::PINTEREST => [
            'supports_image' => true,
            'supports_video' => true,
            /*
             * ⚠️ FALSE HERE MEANS "UNRESOLVED", NOT "PINTEREST HAS NO CAROUSEL".
             *
             * The sources conflict and were not settled during research:
             *   - Pinterest's official "Create boards and pins" guide documents
             *     only `image_url` and `video_id` as media_source types.
             *   - The v5 API reference is reported to also accept
             *     `multiple_image_urls`, taking 2-5 images.
             *   - Pinterest's PRODUCT plainly has carousels (2-5 images, 1:1 or
             *     2:3) in the UI and as carousel ads.
             *
             * So the question is narrower than "does Pinterest do carousels" —
             * it is "does the ORGANIC v5 endpoint expose them". Set false so
             * nothing offers a capability that may not exist, and settle it with
             * one direct check of POST /v5/pins before the Pinterest branch.
             */
            'supports_carousel' => false,
            'carousel_min' => null,
            'carousel_max' => null,
            'image_ratio_min' => null,     // not stated in the API docs
            'image_ratio_max' => null,
            'video_ratio_min' => null,
            'video_ratio_max' => null,
            'max_video_seconds' => null,
            'max_file_bytes' => null,
            // ⚠️ PROVISIONAL. Pinterest's description limit was not verified
            // during research; confirm before the Pinterest branch enforces it.
            'char_limit' => 500,
        ],

        self::THREADS => [
            'supports_image' => true,
            'supports_video' => true,
            'supports_carousel' => true,
            'carousel_min' => 2,
            // ⚠️ 20, not 10. Assuming Instagram's cap applies to Threads would
            // under-use it by half.
            'carousel_max' => 20,
            // ⚠️ The docs give an image bound of "10:1" but state it in a form
            // too ambiguous to encode a MINIMUM from. Max recorded, min left
            // null rather than inferred.
            'image_ratio_min' => null,
            'image_ratio_max' => 10.0,
            'video_ratio_min' => 0.01,
            'video_ratio_max' => 10.0,
            'max_video_seconds' => 300,          // 5 minutes
            'max_file_bytes' => 1073741824,      // 1 GB
            'char_limit' => 500,
        ],
    ];

    /**
     * @return array<string, mixed>|null null for a network we know nothing about
     */
    public static function for(string $network): ?array
    {
        return self::NETWORKS[$network] ?? null;
    }

    /**
     * ⚠️ The 5000 fallback matches the behaviour of the four maps this replaces,
     * every one of which used `?? 5000`. Changing it is a product decision, not
     * a refactor, so it is preserved exactly.
     */
    public static function charLimit(string $network): int
    {
        return self::NETWORKS[$network]['char_limit'] ?? 5000;
    }

    /**
     * The shortest limit among the given networks — a post going to several
     * networks must fit the strictest of them.
     *
     * @param  iterable<string>  $networks
     */
    public static function minCharLimit(iterable $networks): int
    {
        $limits = [];
        foreach ($networks as $n) {
            $limits[] = self::charLimit($n);
        }

        return $limits === [] ? 5000 : min($limits);
    }

    public static function supports(string $network, string $capability): bool
    {
        return (bool) (self::NETWORKS[$network][$capability] ?? false);
    }

    /**
     * The payload handed to the frontend.
     *
     * ⚠️ DRIVER-BACKED ONLY. Shipping Pinterest and Threads to the UI would
     * render selectable options for networks that cannot be connected, let alone
     * posted to. Their data stays server-side until their branches land.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function forFrontend(): array
    {
        $out = [];
        foreach (self::DRIVER_BACKED as $network) {
            $out[$network] = self::NETWORKS[$network];
        }

        return $out;
    }

    /**
     * The tightest carousel range satisfying EVERY given network at once.
     *
     * One post carries one media_urls array to every target account, so the
     * count must satisfy all of them simultaneously: the highest minimum and the
     * lowest maximum.
     *
     * ─── ⚠️ AN UNVERIFIED BOUND IS SKIPPED, NOT TREATED AS INFINITY ──────────
     *
     * Facebook's carousel_max is null — Meta does not document a cap for
     * attached_media. Folding that into a min() as PHP_INT_MAX would make
     * Facebook silently non-constraining, which reads identically to "Facebook
     * allows unlimited" and is exactly the misreading the class header forbids.
     * So null contributes nothing to the bound AND is reported back in
     * `unverified`, so the caller can say "we do not know" rather than
     * inventing a number or pretending there is no limit.
     *
     * A null `max` in the return therefore means "no verified upper bound among
     * these networks" — never "unlimited".
     *
     * @param  iterable<string>  $networks
     * @return array{min: int, max: int|null, unverified: list<string>}
     */
    public static function carouselRange(iterable $networks): array
    {
        $min = self::CAROUSEL_FLOOR;
        $max = null;
        $unverified = [];

        foreach ($networks as $network) {
            $caps = self::NETWORKS[$network] ?? null;
            if ($caps === null) {
                continue;
            }

            if (isset($caps['carousel_min'])) {
                $min = max($min, (int) $caps['carousel_min']);
            }

            if (isset($caps['carousel_max'])) {
                $max = $max === null ? (int) $caps['carousel_max'] : min($max, (int) $caps['carousel_max']);
            } else {
                $unverified[] = $network;
            }
        }

        return ['min' => $min, 'max' => $max, 'unverified' => array_values(array_unique($unverified))];
    }

    /**
     * The tightest image aspect-ratio window satisfying every given network.
     *
     * Same null discipline as carouselRange(): a network documenting no bound
     * (Facebook documents none for Page photos) constrains nothing and is
     * reported in `unverified`. A null min AND max means nothing is enforceable
     * — the caller must then skip ratio validation rather than reject anything.
     *
     * @param  iterable<string>  $networks
     * @return array{min: float|null, max: float|null, unverified: list<string>}
     */
    public static function imageRatioRange(iterable $networks): array
    {
        $min = null;
        $max = null;
        $unverified = [];

        foreach ($networks as $network) {
            $caps = self::NETWORKS[$network] ?? null;
            if ($caps === null) {
                continue;
            }

            $lo = $caps['image_ratio_min'] ?? null;
            $hi = $caps['image_ratio_max'] ?? null;

            if ($lo === null && $hi === null) {
                $unverified[] = $network;

                continue;
            }

            if ($lo !== null) {
                $min = $min === null ? (float) $lo : max($min, (float) $lo);
            }

            if ($hi !== null) {
                $max = $max === null ? (float) $hi : min($max, (float) $hi);
            }
        }

        return ['min' => $min, 'max' => $max, 'unverified' => array_values(array_unique($unverified))];
    }
}
