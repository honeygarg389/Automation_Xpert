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
