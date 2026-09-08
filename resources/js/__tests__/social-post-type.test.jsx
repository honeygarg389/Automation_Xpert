import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import React from 'react';
import fs from 'fs';

/**
 * ⚠️ THE SELECTOR IS GATED ON WHAT THE DRIVERS SEND, NOT ON WHAT THE PLATFORMS
 * ACCEPT — and the assertions that look wrong are the important ones.
 *
 * Instagram documents carousels and LinkedIn documents images. Both are
 * DISABLED here, because InstagramSocialDriver sends image_url => mediaUrls[0]
 * and LinkedInDriver hardcodes shareMediaCategory => 'NONE'. Offering them
 * because the vendor allows them is how attachments vanish between save and
 * publish, with no error anywhere.
 *
 * The prune test is the other half. The billing Change-Plan modal is the
 * precedent for gating on a derived list, and it is INCOMPLETE: it filters the
 * rendered options but leaves the stale value in form state. That is survivable
 * for a single-valued cycle; target_accounts is a multi-select, so a
 * hidden-but-selected account would still be submitted.
 */

vi.mock('react-i18next', () => ({
    useTranslation: () => ({
        t: (k, o) => {
            const en = JSON.parse(fs.readFileSync('resources/js/locales/en.json', 'utf8'));
            const parts = k.split('.');
            let node = en[parts[0]];
            for (let i = 1; i < parts.length && node != null; i++) node = node[parts[i]];
            if (typeof node !== 'string') return k;
            return o ? node.replace(/\{\{(\w+)\}\}/g, (_, n) => o[n] ?? '') : node;
        },
    }),
}));

import PostTypeSelector from '@/Components/Social/PostTypeSelector';
import { carouselRange, accountIsEligible } from '@/Utils/networkCapabilities';

const DRIVER_CAPS = {
    facebook: { image: true, carousel: true, video: false, why: 'photos + attached_media implemented; no video endpoint' },
    instagram: { image: true, carousel: false, video: false, why: 'createContainer() sends image_url => mediaUrls[0] only' },
    linkedin: { image: false, carousel: false, video: false, why: "publish() hardcodes shareMediaCategory => 'NONE'" },
    twitter: { image: false, carousel: false, video: false, why: 'publish() sends text only; no media/upload chain' },
    youtube: { image: false, carousel: false, video: true, why: 'resumable video upload implemented' },
};

const NET_CAPS = {
    facebook: { carousel_min: 2, carousel_max: null },
    instagram: { carousel_min: 2, carousel_max: 10 },
    linkedin: { carousel_min: 2, carousel_max: 20 },
    twitter: { carousel_min: 2, carousel_max: 4 },
};

const acct = (id, network) => ({ id, network, name: `${network} acct`, picture_url: null });

function renderSelector(overrides = {}) {
    const props = {
        accounts: [acct(1, 'facebook'), acct(2, 'instagram'), acct(3, 'linkedin')],
        driverCapabilities: DRIVER_CAPS,
        networkCapabilities: NET_CAPS,
        postType: 'text',
        mediaType: null,
        selectedNetworks: [],
        onChange: vi.fn(),
        ...overrides,
    };
    render(<PostTypeSelector {...props} />);

    return props;
}

describe('PostTypeSelector — driver reality gating', () => {
    it('offers Image when a connected account can actually receive one', () => {
        renderSelector();
        expect(screen.getByTestId('post-type-image')).not.toBeDisabled();
    });

    it('disables Video when no connected account has a video-capable driver', () => {
        renderSelector(); // facebook/instagram/linkedin — none do video
        expect(screen.getByTestId('post-type-video')).toBeDisabled();
    });

    it('enables Video once a YouTube account is connected', () => {
        renderSelector({ accounts: [acct(9, 'youtube')] });
        expect(screen.getByTestId('post-type-video')).not.toBeDisabled();
    });

    it('disables Carousel for an Instagram-only account list, despite Instagram supporting carousels', () => {
        renderSelector({ accounts: [acct(2, 'instagram')], postType: 'image', mediaType: 'single' });
        expect(screen.getByTestId('media-type-carousel')).toBeDisabled();
    });

    it('enables Carousel when Facebook is connected', () => {
        renderSelector({ accounts: [acct(1, 'facebook')], postType: 'image', mediaType: 'single' });
        expect(screen.getByTestId('media-type-carousel')).not.toBeDisabled();
    });

    it('explains WHY a disabled option is disabled rather than just greying it out', () => {
        renderSelector();
        expect(screen.getByTestId('post-type-video')).toHaveAttribute('title');
    });

    it('hides the media sub-choice unless Image is the selected type', () => {
        renderSelector({ postType: 'video' });
        expect(screen.queryByTestId('media-type-carousel')).toBeNull();
    });
});

describe('Carousel range — the unverified-bound trap', () => {
    it('takes the tightest maximum across networks', () => {
        expect(carouselRange(NET_CAPS, ['instagram', 'linkedin'])).toEqual({ min: 2, max: 10, unverified: [] });
    });

    it('never lets an unverified maximum widen a verified one', () => {
        const r = carouselRange(NET_CAPS, ['facebook', 'instagram']);
        expect(r.max).toBe(10);
        expect(r.unverified).toEqual(['facebook']);
    });

    it('returns max:null — not Infinity — when nothing is verified', () => {
        const r = carouselRange(NET_CAPS, ['facebook']);
        expect(r.max).toBeNull();
        expect(r.unverified).toEqual(['facebook']);
    });

    /**
     * ⚠️ Asserts the RENDERED SENTENCE, not the computed object. "2+ images"
     * would read as a promise the platform never made; the wording has to say
     * the maximum is undocumented.
     */
    it('states an unverified maximum honestly instead of implying no limit', () => {
        renderSelector({
            accounts: [acct(1, 'facebook')],
            postType: 'image', mediaType: 'carousel', selectedNetworks: ['facebook'],
        });

        const hint = screen.getByTestId('carousel-range-hint').textContent;
        expect(hint).toContain('at least 2');
        expect(hint).toContain('not documented');
        expect(hint).not.toMatch(/unlimited|no limit/i);
    });

    it('shows a closed range when every selected network is verified', () => {
        renderSelector({
            accounts: [acct(2, 'instagram')],
            postType: 'image', mediaType: 'carousel', selectedNetworks: ['instagram'],
        });

        expect(screen.getByTestId('carousel-range-hint').textContent).toContain('2');
        expect(screen.getByTestId('carousel-range-hint').textContent).toContain('10');
    });
});

describe('accountIsEligible', () => {
    it('treats text as deliverable everywhere', () => {
        expect(accountIsEligible(DRIVER_CAPS, 'linkedin', 'text', null)).toBe(true);
    });

    it('fails closed for a network it has never heard of', () => {
        expect(accountIsEligible(DRIVER_CAPS, 'myspace', 'image', 'single')).toBe(false);
    });
});

/* ── The Composer's prune-and-tell behaviour ───────────────────────────── */

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { auth: { permissions: [] }, flash: {}, timezone: 'UTC' }, url: '/app/social/composer' }),
    router: { get: vi.fn(), post: vi.fn() },
    Head: () => null,
    Link: ({ href, children, ...p }) => <a href={href} {...p}>{children}</a>,
    // ⚠️ STATEFUL on purpose. A mock returning a frozen `data` cannot exercise
    // changePostType at all — the prune would appear to work because nothing
    // ever re-rendered. This one supports both setData(key, value) and the
    // functional setData(prev => next) the prune uses.
    useForm: (initial = {}) => {
        const [data, setD] = React.useState(initial);
        const setData = (k, v) => (typeof k === 'function' ? setD(k) : setD((p) => ({ ...p, [k]: v })));

        return { data, setData, post: vi.fn(), put: vi.fn(), transform: vi.fn(), processing: false, errors: {}, reset: vi.fn() };
    },
}));

vi.mock('@/Layouts/ClientLayout', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('@/Components/MediaUpload', () => ({ default: () => null }));
vi.mock('@/Components/TimezonePicker', () => ({ default: () => null }));
vi.mock('@/Components/ui', () => ({ DatePicker: () => null }));
vi.mock('@/Components/BrandIcons', () => ({ SocialBrandIcon: () => null }));

import SocialComposer from '@/Pages/Social/Composer';

describe('Composer — account pruning on post-type change', () => {
    const accounts = [acct(1, 'facebook'), acct(2, 'instagram'), acct(3, 'linkedin'), acct(4, 'youtube')];

    const renderComposer = () =>
        render(<SocialComposer accounts={accounts} networkCapabilities={NET_CAPS} driverCapabilities={DRIVER_CAPS} />);

    it('disables the chip of an account that cannot deliver the chosen type', () => {
        renderComposer();
        fireEvent.click(screen.getByTestId('post-type-image'));

        expect(screen.getByTestId('account-chip-1')).not.toBeDisabled(); // facebook
        expect(screen.getByTestId('account-chip-3')).toBeDisabled();     // linkedin
    });

    /**
     * ⚠️ THE POINT OF THE WHOLE FEATURE. Selecting LinkedIn as a text post and
     * then switching to Image must REMOVE it from form state, not merely grey
     * the chip. A hidden-but-selected account is still submitted.
     */
    it('prunes a now-incompatible account instead of leaving it selected', () => {
        renderComposer();

        fireEvent.click(screen.getByTestId('account-chip-3')); // select LinkedIn (text post)
        expect(screen.getByTestId('account-chip-3').className).toContain('bg-brand-600');

        fireEvent.click(screen.getByTestId('post-type-image')); // LinkedIn now ineligible

        // ⚠️ ROUND-TRIP, and it has to be. Asserting the chip merely lost its
        // selected styling here proves NOTHING: a disabled chip drops
        // bg-brand-600 from the disabled branch whether or not state was
        // pruned. A mutation that filtered the chips but kept the stale id —
        // exactly the billing modal's behaviour — passed that assertion.
        //
        // Going back to text makes LinkedIn eligible again, so the styling
        // reflects form state once more. Selected here means it was never
        // removed.
        fireEvent.click(screen.getByTestId('post-type-image')); // toggle back to text

        const linkedin = screen.getByTestId('account-chip-3');
        expect(linkedin).not.toBeDisabled();           // eligible again for text
        expect(linkedin.className).not.toContain('bg-brand-600'); // and still not selected
    });

    it('does not restore a pruned account when the type changes back', () => {
        renderComposer();

        fireEvent.click(screen.getByTestId('account-chip-3')); // linkedin selected as text
        fireEvent.click(screen.getByTestId('post-type-image')); // pruned here

        // Back to text, which LinkedIn CAN deliver: if the id had merely been
        // hidden rather than removed, it would light up again here.
        fireEvent.click(screen.getByTestId('post-type-image'));

        const linkedin = screen.getByTestId('account-chip-3');
        expect(linkedin).not.toBeDisabled();
        expect(linkedin.className).not.toContain('bg-brand-600');
    });

    it('lets the author return to a plain text post', () => {
        renderComposer();

        fireEvent.click(screen.getByTestId('post-type-image'));
        expect(screen.getByTestId('account-chip-3')).toBeDisabled();

        fireEvent.click(screen.getByTestId('post-type-image')); // toggle off
        expect(screen.getByTestId('account-chip-3')).not.toBeDisabled();
    });

    it('tells the user what was removed rather than dropping it silently', () => {
        renderComposer();

        fireEvent.click(screen.getByTestId('account-chip-3')); // linkedin
        expect(screen.queryByTestId('pruned-notice')).toBeNull();

        fireEvent.click(screen.getByTestId('post-type-image'));

        const notice = screen.getByTestId('pruned-notice').textContent;
        expect(notice).toContain('linkedin');
        expect(notice).toContain('1');
    });

    it('keeps compatible accounts selected across a type change', () => {
        renderComposer();

        fireEvent.click(screen.getByTestId('account-chip-1')); // facebook
        fireEvent.click(screen.getByTestId('post-type-image'));

        expect(screen.getByTestId('account-chip-1').className).toContain('bg-brand-600');
        expect(screen.queryByTestId('pruned-notice')).toBeNull();
    });
});
