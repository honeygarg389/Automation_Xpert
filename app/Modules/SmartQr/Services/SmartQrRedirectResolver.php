<?php

namespace App\Modules\SmartQr\Services;

use App\Models\Scopes\WorkspaceScope;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\SmartQr\Models\SmartQrAssignment;
use App\Modules\SmartQr\Models\SmartQrCode;
use App\Modules\SmartQr\Support\QrRedirectOutcome;
use App\Modules\SmartQr\Support\SmartQrStatus;
use App\Modules\Whatsapp\Models\WhatsappPhoneNumber;

/**
 * Resolves a public token to an outcome. §8 steps 1–7.
 *
 * ─── ⚠️ THE THIRD PUBLIC ENTRY POINT, AND THE DISCOVERY BYPASS ──────────────
 *
 * After `ChannelAccountRouting::findForInbound()` and the inbound webhook jobs,
 * this is the third path a stranger reaches with no authenticated user. It has
 * the same shape: **the tenant must be resolved BEFORE the tenant scope can
 * apply, because the workspace is the ANSWER being sought.**
 *
 * ⚠️ It differs from `findForInbound` in ONE way worth stating. `ChannelAccount`
 * is workspace-scoped, so that method needs a bypass on its FIRST hop. This one
 * does not: `SmartQrCode` carries no scope at all (R-4, lifecycle-owned), so the
 * token lookup is naturally unscoped. The bypass is needed one hop later, on
 * `smart_qr_assignments` — which IS scoped and, with no ambient context, fails
 * closed and would report EVERY code on earth as unassigned.
 *
 * ─── ⚠️ NO CACHING. A DELIBERATE REFUSAL OF A SPEC REQUIREMENT. ─────────────
 *
 * §8 asks to "cache stable QR configuration if safe" and to "ensure cache
 * invalidation after assignment/status/message update".
 *
 * **Refused.** That is a cache whose correctness depends on somebody remembering
 * to invalidate it — which is BUG-036 exactly, recorded one week before this was
 * written: five invalidators wired, and the plan editor that needed one never
 * dispatched it, so stale limits were enforced silently for an hour.
 *
 * Here the stale value is not a limit. **A cached assignment after a
 * reassignment points a QR at the WRONG TENANT'S WhatsApp number** — a
 * cross-tenant leak introduced by an optimisation, on a lookup that is already a
 * single hit against a unique index.
 *
 * Do not add caching here as a performance win. Bring a measurement first, and
 * an invalidation path that cannot be forgotten.
 */
class SmartQrRedirectResolver
{
    /**
     * @return array{
     *     outcome: QrRedirectOutcome,
     *     assignment: SmartQrAssignment|null,
     *     phone: string|null,
     *     message: string|null
     * }
     */
    public function resolve(string $token): array
    {
        $miss = fn (QrRedirectOutcome $o, ?SmartQrAssignment $a = null) => [
            'outcome' => $o, 'assignment' => $a, 'phone' => null, 'message' => null,
        ];

        // ── §8.1 — indexed lookup on the unique token ───────────────────────
        //
        // ⚠️ `public_token` ONLY. `serial_number` is printed on the sticker and
        // sequential; resolving one here would turn every distinct response
        // below into an inventory oracle. Pinned by
        // `a_serial_number_is_not_accepted_as_a_token`.
        $code = SmartQrCode::query()->where('public_token', $token)->first();

        if ($code === null) {
            return $miss(QrRedirectOutcome::INVALID);
        }

        // ── §8.2 — retired is a PHYSICAL state (R-10), checked on the code ──
        if (in_array($code->status, [SmartQrStatus::CODE_RETIRED, SmartQrStatus::CODE_LOST, SmartQrStatus::CODE_DAMAGED], true)) {
            return $miss(QrRedirectOutcome::RETIRED);
        }

        // ── §8.4 — the current assignment. THE DISCOVERY BYPASS. ────────────
        $assignment = SmartQrAssignment::query()
            ->withoutGlobalScope(WorkspaceScope::class)
            ->where('smart_qr_code_id', $code->id)
            ->whereNull('unassigned_at')
            ->first();

        if ($assignment === null) {
            return $miss(QrRedirectOutcome::UNASSIGNED);
        }

        // ── §8.3 — activation status, on the ASSIGNMENT (R-10) ──────────────
        if ($assignment->status !== SmartQrStatus::ASSIGNMENT_ACTIVE) {
            return $miss(QrRedirectOutcome::INACTIVE, $assignment);
        }

        // ── §8.5 — start and expiry ─────────────────────────────────────────
        $now = now();

        if ($assignment->starts_at !== null && $now->lt($assignment->starts_at)) {
            // Not yet live reads as EXPIRED to the public: both mean "not now",
            // and a page saying "this becomes active on the 3rd" would leak the
            // tenant's rollout schedule.
            return $miss(QrRedirectOutcome::EXPIRED, $assignment);
        }

        if ($assignment->expires_at !== null && $now->gt($assignment->expires_at)) {
            return $miss(QrRedirectOutcome::EXPIRED, $assignment);
        }

        // ── §8.6 — the channel, and the DIALABLE number ─────────────────────
        //
        // ⚠️ THE SEVENTH STATE. `ChannelAccount.phone_number_id` is a Meta API
        // identifier, NOT a phone number — the E.164 number lives on
        // `whatsapp_phone_numbers.display_phone`. So a correctly assigned,
        // active, in-date QR can still have nothing to dial.
        $phone = $this->dialableNumber($assignment);

        if ($phone === null) {
            return $miss(QrRedirectOutcome::UNCONFIGURED, $assignment);
        }

        return [
            'outcome' => QrRedirectOutcome::REDIRECT,
            'assignment' => $assignment,
            'phone' => $phone,
            'message' => $this->effectiveMessage($assignment),
        ];
    }

    /**
     * The E.164 number to dial, or null.
     *
     * ⚠️ Scope dropped for the same reason as the assignment: there is no
     * authenticated user, and the workspace was only just discovered. The
     * explicit `workspace_id` below IS the boundary.
     */
    private function dialableNumber(SmartQrAssignment $assignment): ?string
    {
        $channel = ChannelAccount::query()
            ->withoutGlobalScope(WorkspaceScope::class)
            ->where('id', $assignment->channel_account_id)
            ->where('workspace_id', $assignment->workspace_id)
            ->where('status', 'active')
            ->first();

        if ($channel === null || $channel->phone_number_id === null) {
            return null;
        }

        $display = WhatsappPhoneNumber::query()
            ->where('phone_number_id', $channel->phone_number_id)
            ->value('display_phone');

        return $this->normalise($display);
    }

    /**
     * wa.me wants digits only, no `+`, no spaces or punctuation.
     *
     * Kept here rather than reaching for a shared helper because none exists —
     * `display_phone` is stored free-form and every consumer normalises at the
     * point of use. Returns null for anything implausibly short, so a malformed
     * row produces the UNCONFIGURED page rather than a wa.me link to nowhere.
     */
    private function normalise(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        return strlen($digits) >= 8 ? $digits : null;
    }

    /**
     * §7's message hierarchy — TWO tiers, deliberately.
     *
     * ⚠️ §7 specifies three: global, batch, individual. **The global tier is
     * deliberately not built.** There is no system setting for it, and adding
     * one would ship a third tier that nobody has configured — a setting whose
     * value is empty on every installation, checked on every scan.
     *
     * Two tiers work today: the assignment overrides the batch. §7's top tier
     * can arrive when something needs it, and the fallback chain below is where
     * it slots in.
     */
    private function effectiveMessage(SmartQrAssignment $assignment): string
    {
        if (is_string($assignment->default_message) && trim($assignment->default_message) !== '') {
            return $assignment->default_message;
        }

        $batchMessage = $assignment->code?->batch?->default_message;

        return is_string($batchMessage) ? $batchMessage : '';
    }

    /** The wa.me deep link. §8 step 10. */
    public function deepLink(string $phone, string $message): string
    {
        $url = 'https://wa.me/'.$phone;

        return trim($message) === ''
            ? $url
            : $url.'?text='.rawurlencode($message);
    }
}
