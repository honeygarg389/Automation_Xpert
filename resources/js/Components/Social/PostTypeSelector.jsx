import { Image as ImageIcon, Video, Layers, Square, Info } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import {
    POST_TYPE_IMAGE, POST_TYPE_VIDEO, POST_TYPE_TEXT, MEDIA_SINGLE, MEDIA_CAROUSEL,
    driverSupports, carouselRange,
} from '@/Utils/networkCapabilities';

/**
 * Post Type — Image / Video, and for Image, Single / Carousel.
 *
 * ─── ⚠️ GATED ON DRIVER REALITY, NOT ON WHAT THE PLATFORM ACCEPTS ───────────
 *
 * `driverCapabilities` is what our drivers actually send today, which is much
 * less than the networks accept. Instagram's API takes carousels; our driver
 * sends mediaUrls[0] and drops the rest with NO error, so a carousel chosen for
 * Instagram would look saved, look published, and arrive as one image. Offering
 * the option because the platform allows it is how media disappears silently.
 *
 * ─── DISABLED AND VISIBLE, NOT HIDDEN ──────────────────────────────────────
 *
 * An option with no eligible account is rendered disabled with a reason, rather
 * than removed. Hiding it makes the product look like it never had the feature
 * and gives the user nothing to act on; "Video — no connected account supports
 * it yet" tells them the gap is their account list, which they can change. It
 * also keeps the surface honest as Branch 4 lands: options light up rather than
 * appearing from nowhere.
 */
function Option({ active, disabled, reason, icon: Icon, label, onClick, testid }) {
return (
    <button
        type="button"
        data-testid={testid}
        disabled={disabled}
        title={disabled ? reason : undefined}
        onClick={onClick}
        className={`flex items-center gap-2 rounded-lg border px-3 py-2 text-sm transition ${
            disabled
                ? 'cursor-not-allowed border-neutral-200 dark:border-neutral-700 text-neutral-400 dark:text-neutral-600 opacity-60'
                : active
                    ? 'border-brand-600 bg-brand-50 dark:bg-brand-900/30 text-brand-700 dark:text-brand-300'
                    : 'border-neutral-300 dark:border-neutral-600 text-neutral-700 dark:text-neutral-300 hover:border-brand-300'
        }`}
    >
        <Icon className="h-4 w-4" />
        {label}
        {disabled && <Info className="h-3.5 w-3.5" />}
    </button>
);
}

export default function PostTypeSelector({
    accounts = [],
    driverCapabilities = {},
    networkCapabilities = {},
    postType,
    mediaType,
    onChange,
    selectedNetworks = [],
}) {
    const { t } = useTranslation();

    const networksOf = (capability) =>
        [...new Set(accounts.map((a) => a.network))].filter((n) =>
            driverSupports(driverCapabilities, n, capability));

    const imageNetworks = networksOf('image');
    const videoNetworks = networksOf('video');
    const carouselNetworks = networksOf('carousel');

    const range = carouselRange(networkCapabilities, selectedNetworks);

    /**
     * ⚠️ Clicking the ACTIVE type clears it, returning the post to plain text.
     *
     * Without this there is no way back: the selector offers Image and Video
     * only, so a mis-click became permanent for the life of the draft — the
     * author could never restore a text-only post, and any account pruned on
     * the way in stayed unreachable. A selector you cannot un-select is a trap,
     * not a choice.
     */
    const choose = (nextType, nextMedia) =>
        (nextType === postType && nextMedia === mediaType
            ? onChange(POST_TYPE_TEXT, null)
            : onChange(nextType, nextMedia));

    return (
        <div className="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 p-4">
            <p className="text-xs font-semibold text-neutral-500 dark:text-neutral-400 uppercase mb-3">
                {t('social.post_type')}
            </p>

            <div className="flex flex-wrap gap-2">
                <Option
                    testid="post-type-image"
                    active={postType === POST_TYPE_IMAGE}
                    disabled={imageNetworks.length === 0}
                    reason={t('social.post_type_unavailable', { kind: t('social.post_type_image') })}
                    icon={ImageIcon}
                    label={t('social.post_type_image')}
                    onClick={() => choose(POST_TYPE_IMAGE, mediaType ?? MEDIA_SINGLE)}
                />
                <Option
                    testid="post-type-video"
                    active={postType === POST_TYPE_VIDEO}
                    disabled={videoNetworks.length === 0}
                    reason={t('social.post_type_unavailable', { kind: t('social.post_type_video') })}
                    icon={Video}
                    label={t('social.post_type_video')}
                    onClick={() => choose(POST_TYPE_VIDEO, null)}
                />
            </div>

            {postType === POST_TYPE_IMAGE && (
                <div className="mt-3 pl-1">
                    <div className="flex flex-wrap gap-2">
                        <Option
                            testid="media-type-single"
                            active={mediaType !== MEDIA_CAROUSEL}
                            disabled={false}
                            icon={Square}
                            label={t('social.media_type_single')}
                            onClick={() => choose(POST_TYPE_IMAGE, MEDIA_SINGLE)}
                        />
                        <Option
                            testid="media-type-carousel"
                            active={mediaType === MEDIA_CAROUSEL}
                            disabled={carouselNetworks.length === 0}
                            reason={t('social.post_type_unavailable', { kind: t('social.media_type_carousel') })}
                            icon={Layers}
                            label={t('social.media_type_carousel')}
                            onClick={() => choose(POST_TYPE_IMAGE, MEDIA_CAROUSEL)}
                        />
                    </div>

                    {mediaType === MEDIA_CAROUSEL && (
                        <p className="mt-2 text-xs text-neutral-500 dark:text-neutral-400" data-testid="carousel-range-hint">
                            {/*
                              ⚠️ An unverified maximum is stated as unknown, never
                              rendered as "no limit". Facebook documents no cap for
                              attached_media, and printing "2+ images" would read as
                              a promise we cannot keep.
                            */}
                            {range.max === null
                                ? t('social.carousel_range_open', { min: range.min, networks: range.unverified.join(', ') })
                                : range.unverified.length > 0
                                    ? t('social.carousel_range_partial', { min: range.min, max: range.max, networks: range.unverified.join(', ') })
                                    : t('social.carousel_range', { min: range.min, max: range.max })}
                        </p>
                    )}
                </div>
            )}
        </div>
    );
}
