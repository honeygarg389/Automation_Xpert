<?php

namespace App\Modules\SmartQr\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Modules\SmartQr\Jobs\RecordQrScanJob;
use App\Modules\SmartQr\Services\SmartQrRedirectResolver;
use App\Modules\SmartQr\Services\SmartQrScanFingerprint;
use App\Modules\SmartQr\Support\QrRedirectOutcome;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * GET /q/{token} — §8. The public redirect.
 *
 * ⚠️ THE FIRST SURFACE IN THIS MODULE A STRANGER REACHES. No authentication, no
 * workspace context, no session assumptions.
 *
 * ─── ⚠️ THE FAILURE MODE IS DELIBERATE AND ONE-DIRECTIONAL ──────────────────
 *
 * **The redirect must survive; the scan is expendable.** A broken redirect is a
 * customer standing in a shop looking at a dead sticker. A lost scan is one
 * missing row in an analytics table.
 *
 * So the dispatch is wrapped and its failure swallowed after logging. Note what
 * that actually protects against: a stopped WORKER loses nothing — the job row
 * is written and processed later. The case this guards is the database being
 * unwritable, where `dispatch()` throws. By then the read has already happened,
 * so the redirect still has everything it needs.
 *
 * Ordered read -> dispatch -> redirect rather than `afterResponse()`, which
 * would lose the scan outright if the process died between response and
 * callback.
 */
class PublicQrController extends Controller
{
    public function __construct(
        private readonly SmartQrRedirectResolver $resolver,
        private readonly SmartQrScanFingerprint $fingerprint,
    ) {}

    public function __invoke(Request $request, string $token): RedirectResponse|Response
    {
        $resolved = $this->resolver->resolve($token);
        $outcome = $resolved['outcome'];

        if ($outcome === QrRedirectOutcome::REDIRECT) {
            $this->recordScan($request, (int) $resolved['assignment']->id);

            // ⚠️ away(), not redirect(): wa.me is off-domain, and redirect()
            // would treat it as a path on this host.
            return redirect()->away(
                $this->resolver->deepLink($resolved['phone'], (string) $resolved['message'])
            );
        }

        // ⚠️ THE SEVENTH STATE — the log says what the page cannot.
        //
        // UNCONFIGURED renders the INACTIVE page, because §8 forbids revealing
        // internal configuration and "your tenant's WABA has no phone number" is
        // exactly that. But an operator needs to find it, so it is logged
        // distinguishably, with the assignment id and nothing about the visitor.
        if ($outcome === QrRedirectOutcome::UNCONFIGURED) {
            Log::warning('smart_qr.redirect.unconfigured', [
                'assignment_id' => $resolved['assignment']?->id,
                'workspace_id' => $resolved['assignment']?->workspace_id,
                'reason' => 'assignment is active and in date, but its channel has no dialable number',
            ]);
        }

        return $this->page($outcome);
    }

    /**
     * ⚠️ Fingerprints are computed HERE and only hashes are handed on.
     *
     * The job never receives an address or a user agent, so a raw IP cannot
     * reach the `jobs` table — which is durable storage, and would otherwise
     * hold precisely what §10 forbids persisting.
     */
    private function recordScan(Request $request, int $assignmentId): void
    {
        try {
            RecordQrScanJob::dispatch(
                $assignmentId,
                $this->fingerprint->hashIp($request->ip()),
                $this->fingerprint->hashUserAgent($request->userAgent()),
                $this->fingerprint->looksLikeBot($request->userAgent()),
                $this->fingerprint->refererHost($request->headers->get('referer')),
                now()->toDateTimeString(),
            );
        } catch (\Throwable $e) {
            // The customer's redirect is worth more than the row. Logged so a
            // silent gap in analytics is explainable afterwards.
            Log::warning('smart_qr.scan.dispatch_failed', [
                'assignment_id' => $assignmentId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The themed refusal pages.
     *
     * ⚠️ Blade, not Inertia. Inertia boots `HandleInertiaRequests`, which does
     * tenant, locale and branding work on every response — none of it needed to
     * say "this QR is inactive", and all of it paid for on a path that must stay
     * cheap. Reuses `errors.layout`, so these match the existing 404/500 pages.
     *
     * ⚠️ Every string below is generic by design (§8). No tenant name, no
     * client, no batch, no serial, no channel, no dates.
     */
    private function page(QrRedirectOutcome $outcome): Response
    {
        [$title, $message] = match ($outcome) {
            QrRedirectOutcome::UNASSIGNED => [
                'This QR code is not set up yet',
                'It has not been linked to a business account. If you have just received it, please try again shortly.',
            ],
            QrRedirectOutcome::EXPIRED => [
                'This QR code has expired',
                'It is no longer in use. Please contact the business directly.',
            ],
            QrRedirectOutcome::RETIRED => [
                'This QR code is no longer active',
                'It has been permanently withdrawn. Please contact the business directly.',
            ],
            // INACTIVE and UNCONFIGURED share this page on purpose.
            default => [
                'This QR code is currently inactive',
                'It is not accepting messages right now. Please contact the business directly.',
            ],
        };

        return response()->view('smartqr.unavailable', [
            'code' => 'QR',
            'title' => $title,
            'message' => $message,
        ], $outcome->httpStatus());
    }
}
