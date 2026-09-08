/**
 * Frontend accessors for the capability data exposed by
 * App\Modules\Social\Support\NetworkCapabilities.
 *
 * ⚠️ THERE IS NO FALLBACK TABLE HERE, DELIBERATELY. Keeping a copy of the limits
 * in JS "just in case the prop is missing" would recreate the exact duplication
 * this replaces — four hand-maintained copies of one map, which all had to be
 * edited when TikTok was removed and none of which would have failed if missed.
 *
 * When the prop is absent the helpers fall back to the same `?? 5000` the old
 * maps used, so behaviour is unchanged, but there is nothing to drift.
 */

const DEFAULT_CHAR_LIMIT = 5000;

/** @param caps the `networkCapabilities` Inertia prop, keyed by network slug */
export function charLimit(caps, network) {
    return caps?.[network]?.char_limit ?? DEFAULT_CHAR_LIMIT;
}

/**
 * The strictest limit among several networks — a post going to more than one
 * must fit the shortest of them.
 */
export function minCharLimit(caps, networks) {
    const list = (networks ?? []).filter(Boolean);
    if (list.length === 0) return DEFAULT_CHAR_LIMIT;

    return Math.min(...list.map((n) => charLimit(caps, n)));
}

/** Whether a network supports a capability, e.g. supports_video. */
export function supports(caps, network, capability) {
    return Boolean(caps?.[network]?.[capability]);
}

/* ─── DRIVER REALITY ────────────────────────────────────────────────────────
 *
 * ⚠️ The helpers below read the `driverCapabilities` prop, which is a DIFFERENT
 * question from `networkCapabilities` above. See
 * App\Modules\Social\Support\DriverCapabilities for why both exist.
 *
 * Short version: networkCapabilities says what the PLATFORM accepts,
 * driverCapabilities says what OUR CODE actually sends. Gate on the second when
 * deciding whether an option may be offered; use the first for counts and
 * shapes once it is.
 */

export const POST_TYPE_IMAGE = 'image';
export const POST_TYPE_VIDEO = 'video';
export const POST_TYPE_TEXT = 'text';
export const MEDIA_SINGLE = 'single';
export const MEDIA_CAROUSEL = 'carousel';

/** Unknown network => false. Fail closed, matching DriverCapabilities::supports(). */
export function driverSupports(driverCaps, network, capability) {
    return Boolean(driverCaps?.[network]?.[capability]);
}

/** Why a network can't do something, for tooltips. */
export function driverReason(driverCaps, network) {
    return driverCaps?.[network]?.why ?? 'no driver';
}

/** The capability a (postType, mediaType) pair needs; null when unrestricted. */
export function requiredCapability(postType, mediaType) {
    if (postType === POST_TYPE_VIDEO) return 'video';
    if (postType === POST_TYPE_IMAGE) return mediaType === MEDIA_CAROUSEL ? 'carousel' : 'image';

    return null;
}

/** Can this specific account's network deliver this post shape? */
export function accountIsEligible(driverCaps, network, postType, mediaType) {
    const capability = requiredCapability(postType, mediaType);

    return capability === null ? true : driverSupports(driverCaps, network, capability);
}

/**
 * The tightest carousel range across several networks.
 *
 * ⚠️ Mirrors NetworkCapabilities::carouselRange() including its null discipline:
 * an unverified carousel_max (Facebook's) contributes NOTHING to the bound and
 * is reported in `unverified` instead. Returning max:null here means "no
 * verified upper bound", never "unlimited" — the caller must say so out loud
 * rather than rendering an open-ended range.
 */
export function carouselRange(caps, networks) {
    const list = (networks ?? []).filter(Boolean);
    let min = 2;
    let max = null;
    const unverified = [];

    for (const n of list) {
        const c = caps?.[n];
        if (!c) continue;

        if (c.carousel_min != null) min = Math.max(min, c.carousel_min);

        if (c.carousel_max != null) max = max === null ? c.carousel_max : Math.min(max, c.carousel_max);
        else unverified.push(n);
    }

    return { min, max, unverified: [...new Set(unverified)] };
}
