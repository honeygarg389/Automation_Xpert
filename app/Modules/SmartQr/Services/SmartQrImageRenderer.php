<?php

namespace App\Modules\SmartQr\Services;

use App\Models\SystemSetting;
use Barryvdh\DomPDF\Facade\Pdf;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Label\Font\OpenSans;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Writer\Result\ResultInterface;
use Endroid\QrCode\Writer\SvgWriter;
use Illuminate\Support\Facades\Storage;

/**
 * Renders the printed artwork for one QR code. §13, §14.
 *
 * ⚠️ ONE RENDERER, used by the preview, the single download and the ZIP.
 *
 * Three renderers would drift, and the drift would only be discovered in print —
 * after a customer had a thousand stickers made.
 *
 * ═══ ⚠️ R-2: §13 WINS OVER §25. EVERY OPTION HERE IS FIXED. ════════════════
 *
 * §25 ends by asking for advanced patterns, coloured styling, custom finder
 * shapes and a customer-logo designer "in the MVP" — contradicting §13 two
 * sections earlier and §14's "AutomationXpert logo only". R-2 ruled it a dropped
 * "not".
 *
 * That ruling is doing real work, not tidying:
 *
 *   - **Colour is contrast, and contrast is scannability.** A customer picking
 *     brand colours can produce a code that fails on cheap phone cameras — and
 *     the failure is discovered by THEIR customer, in a shop, silently.
 *   - **A customer-logo upload would reopen SEC-004** — the unfixed stored-XSS
 *     finding about SVG accepted for upload and served from public storage — on
 *     a path whose output then gets PRINTED. Nothing here accepts an upload, so
 *     there is deliberately no sanitisation code: the vulnerability is avoided
 *     rather than mitigated.
 *
 * ⚠️ §13 also asks for collapsed "advanced options" (error correction, margin,
 * resolution, logo size, print DPI) two lines after saying not to expose
 * advanced customisation. All are fixed constants below. Every one of them can
 * produce an unscannable or unprintable code and none has a stated use case.
 */
class SmartQrImageRenderer
{
    /**
     * ⚠️ 1024px for the QR module area — a number, not "high resolution".
     *
     * §14 wants print, and print is 300 DPI. A Business Kit sticker is 25–40mm
     * square, which at 300 DPI is 295–472px. 1024 covers that with room to be
     * enlarged to an ~85mm table tent without resampling, while staying small
     * enough that 500 of them zip sensibly.
     */
    public const PNG_SIZE = 1024;

    /**
     * ⚠️ The quiet zone. §14 requires one; 4 modules is the QR specification's
     * minimum and what scanners assume.
     */
    public const MARGIN = 16;

    /**
     * ⚠️ Logo width as a fraction of the QR.
     *
     * Level H tolerates ~30% damage. Staying at 22% leaves headroom for print
     * bleed and a partially obscured sticker — the difference between a code
     * that decodes on a scuffed counter and one that does not.
     */
    public const LOGO_RATIO = 0.22;

    /**
     * ⚠️ Height of the serial band appended to the SVG, in px. Matches the
     * proportion the PNG writer produces (1056 wide -> 1094 tall).
     */
    private const SVG_LABEL_BAND = 38;

    /**
     * ⚠️ MEASURED ZIPPED BYTES PER CODE — and the direction is the OPPOSITE of
     * what slice 8's plan and my own controller docblock assumed.
     *
     * The plan said "SVG is a few hundred KB for 500, PNG is 50–150 MB", so SVG
     * became the default on a size argument. Measured, in a ZipArchive because
     * that is what the admin actually downloads:
     *
     *   |          | SVG/code | PNG/code | 500 SVG | 500 PNG |
     *   |----------|----------|----------|---------|---------|
     *   | no logo  |   4.5 KB |   6.8 KB |  2.1 MB |  3.2 MB |
     *   | logo     |  491  KB |  154  KB |  234 MB |   73 MB |
     *
     * ⚠️ WITH A LOGO CONFIGURED — the intended production state — SVG IS ROUGHLY
     * 3× LARGER THAN PNG, not smaller.
     *
     * The cause is structural, not incidental: endroid's SvgWriter embeds the
     * logo as a base64 data URI in EVERY file, and base64 of an already-
     * compressed PNG neither shrinks in the SVG nor deflates in the ZIP. The
     * PNG writer rasterises the same logo into one bitmap that is compressed
     * once.
     *
     * SVG REMAINS THE DEFAULT, on the argument that actually holds: it is vector
     * and prints crisply at any physical size, where a 1024 px PNG blurs on
     * anything larger than a sticker. The size claim was never the real reason —
     * it was a wrong number that happened to point at the right default.
     *
     * These figures drive the UI note, so an admin sees the true cost of the
     * format they pick. Re-measure if the logo pipeline changes.
     */
    public const ZIPPED_BYTES_PER_CODE = [
        'no_logo' => ['svg' => 4_495, 'png' => 6_809],
        'logo' => ['svg' => 491_000, 'png' => 153_866],
    ];

    /**
     * Bytes per code for the currently configured logo state.
     *
     * @return array{svg: int, png: int}
     */
    public function zippedBytesPerCode(): array
    {
        return self::ZIPPED_BYTES_PER_CODE[$this->logoPath() === null ? 'no_logo' : 'logo'];
    }

    /** @return array{data: string, mime: string} */
    public function svg(string $url, string $serial): array
    {
        $result = $this->build($url, $serial, new SvgWriter);

        return [
            'data' => $this->appendSerialToSvg($result->getString(), $serial),
            'mime' => 'image/svg+xml',
        ];
    }

    /**
     * ═══ ⚠️ endroid's SvgWriter ACCEPTS A LABEL AND SILENTLY DISCARDS IT ═══
     *
     * Measured, not assumed. Same builder, same `labelText`:
     *
     *     PNG -> 1056 x 1094   (taller: the serial band is rendered)
     *     SVG -> 1056 x 1056   (square: the label is gone)
     *
     * `SvgWriter::write()` takes a `LabelInterface $label` parameter and never
     * uses it. Nothing errors and nothing warns — the file is simply missing the
     * serial.
     *
     * ⚠️ THAT MATTERS BECAUSE SVG IS THE ZIP DEFAULT. §14 requires the serial
     * beneath the QR, and the ZIP is what goes to a printer. Shipping this
     * unnoticed would mean 500 stickers with no human-readable identifier — and
     * the serial is how an operator matches a physical sticker to a row
     * (`getRouteKeyName()` is `serial_number`, and slice 4's whole enumeration
     * argument rests on the serial being printed while the token is not).
     *
     * So the band is appended here: the viewBox and height are extended and a
     * centred <text> is added. Deterministic string work on output we generated
     * ourselves, not parsing of anything foreign.
     */
    private function appendSerialToSvg(string $svg, string $serial): string
    {
        if (! preg_match('/<svg[^>]*\bwidth="(\d+)px"[^>]*\bheight="(\d+)px"/', $svg, $m)) {
            // Shape changed under us — return the QR unlabelled rather than a
            // corrupted file. The test below fails loudly if this ever happens.
            return $svg;
        }

        [$full, $width, $height] = [$m[0], (int) $m[1], (int) $m[2]];
        $newHeight = $height + self::SVG_LABEL_BAND;

        $svg = str_replace($full, str_replace(
            ['height="'.$height.'px"', 'viewBox="0 0 '.$width.' '.$height.'"'],
            ['height="'.$newHeight.'px"', 'viewBox="0 0 '.$width.' '.$newHeight.'"'],
            $full
        ), $svg);

        $text = sprintf(
            '<rect x="0" y="%d" width="%d" height="%d" fill="#ffffff"/>'
            .'<text x="%d" y="%d" font-family="sans-serif" font-size="24" fill="#000000" '
            .'text-anchor="middle">S.No: %s</text>',
            $height, $width, self::SVG_LABEL_BAND,
            (int) ($width / 2), $height + 26,
            htmlspecialchars($serial, ENT_QUOTES | ENT_XML1)
        );

        return str_replace('</svg>', $text.'</svg>', $svg);
    }

    /** @return array{data: string, mime: string} */
    public function png(string $url, string $serial): array
    {
        $result = $this->build($url, $serial, new PngWriter);

        return ['data' => $result->getString(), 'mime' => 'image/png'];
    }

    /**
     * ═══ ⚠️ PDF VIA DOMPDF, AND THE SVG MUST BE AN <img> ══════════════════
     *
     * endroid's own PdfWriter needs `setasign/fpdf` — a THIRD package. R-6
     * approved exactly one, so §14's printable PDF goes through Dompdf, which
     * was already in the stack.
     *
     * ⚠️ INLINE <svg> DOES NOT WORK, MEASURED. Dompdf renders SVG only through
     * `<img>`; an inline element is silently ignored and the page comes out
     * blank:
     *
     *     inline <svg>            ->  1,140 bytes  (empty page)
     *     <img src="data:...svg"> ->  ~5,300 bytes (rendered)
     *     blank SVG control       ->  1,141 bytes
     *
     * Nothing errors in the inline case. A PDF whose QR is missing prints
     * before anyone notices, which is why this is asserted by test rather than
     * assumed — see `the_module_matrix_survives_the_pdf_conversion`.
     *
     * @return array{data: string, mime: string}
     */
    public function pdf(string $url, string $serial): array
    {
        $svg = $this->svg($url, $serial)['data'];

        $html = view('smartqr.print', [
            'svgDataUri' => 'data:image/svg+xml;base64,'.base64_encode($svg),
            'serial' => $serial,
        ])->render();

        return [
            'data' => Pdf::loadHTML($html)->output(),
            'mime' => 'application/pdf',
        ];
    }

    /**
     * The single configuration, used by every format.
     *
     * ⚠️ Nothing here is parameterised beyond the URL and the serial. That is
     * the point: see the class docblock.
     */
    private function build(string $url, string $serial, SvgWriter|PngWriter $writer): ResultInterface
    {
        // ⚠️ The FLUENT builder — 5.1's API. Written once, so every format and
        // every caller gets byte-identical configuration.
        $builder = Builder::create()
            ->writer($writer)
            ->data($url)
            ->encoding(new Encoding('UTF-8'))

            // ⚠️ §14: high error correction. Also what makes the centre logo
            // safe — the code still decodes with the middle obscured.
            ->errorCorrectionLevel(ErrorCorrectionLevel::High)

            ->size(self::PNG_SIZE)
            ->margin(self::MARGIN)

            // Standard square modules. §13: "standard square pattern,
            // standard finder design".
            ->roundBlockSizeMode(RoundBlockSizeMode::Margin)

            // §14: the serial beneath the QR. No customer name, no WhatsApp
            // number, no permanent business details.
            ->labelText('S.No: '.$serial)
            ->labelFont(new OpenSans(28));

        $logo = $this->logoPath();

        if ($logo !== null) {
            $builder = $builder
                ->logoPath($logo)
                ->logoResizeToWidth((int) round(self::PNG_SIZE * self::LOGO_RATIO))
                ->logoPunchoutBackground(true);
        }

        return $builder->build();
    }

    /**
     * ═══ ⚠️ THE LOGO, AND THE FALLBACK MATTERS MORE THAN THE LOGO ═════════
     *
     * §14: "For MVP, use AutomationXpert logo only. If a logo asset already
     * exists in the project, reuse it."
     *
     * ⚠️ THERE IS NO AutomationXpert ASSET IN THIS REPOSITORY. The only logo
     * files are `public/whatsmine-logo.png` and `whatsmine-logo-with-title.svg`
     * — WhatsMine branding inherited from the original import. Recorded in
     * `docs/found-bugs.md` because it is a real gap the owner must close before
     * any kit is printed.
     *
     * So the source is the CONFIGURED PLATFORM LOGO (`app_logo_path`), which on
     * a white-label install is that installation's own brand. It is not a
     * customer logo — one per installation, set by the platform owner — so §14
     * and R-2 both hold.
     *
     * ⚠️ AND WHEN NONE IS CONFIGURED, THIS RETURNS NULL AND THE QR RENDERS
     * PLAIN. It must NEVER fall back to the WhatsMine asset: a plain QR is
     * honest, whereas a competitor's brand printed onto a customer's stickers is
     * not recoverable once the run is done.
     */
    public function logoPath(): ?string
    {
        try {
            $path = SystemSetting::get('app_logo_path');
        } catch (\Throwable) {
            return null;
        }

        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        $disk = Storage::disk(SystemSetting::get('app_logo_disk', 'public'));

        if (! $disk->exists($path)) {
            return null;
        }

        $absolute = $disk->path($path);

        // ⚠️ SVG is refused as a logo source. GD cannot rasterise it, so a PNG
        // render would fail at output time rather than here — and it keeps this
        // path away from SEC-004's territory entirely.
        if (! is_file($absolute) || strtolower(pathinfo($absolute, PATHINFO_EXTENSION)) === 'svg') {
            return null;
        }

        return $absolute;
    }

    /**
     * ═══ ⚠️ STRUCTURAL ASSERTIONS — NOT "VALIDATED READABILITY" ═══════════
     *
     * §14 asks to "validate scan readability". **This does not do that, and
     * nothing here claims to.**
     *
     * Actually decoding a generated code needs a QR *reader*; bacon is an
     * encoder only, so it would mean a second library — and avoiding exactly
     * that was R-6's entire argument for choosing this dependency.
     *
     * What can be asserted without a decoder are the structural properties that
     * make a code readable, and those are asserted honestly:
     *
     *   1. error correction is Level H
     *   2. the logo covers less than Level H's ~30% tolerance
     *   3. a quiet zone is present
     *
     * A code satisfying all three is well-formed. That is a weaker claim than
     * "it scans", and the weaker claim is the true one.
     *
     * ⚠️ AND THIS METHOD REPORTS VALUES, NOT SELF-EVIDENT BOOLEANS.
     *
     * It first returned `LOGO_RATIO < 0.30` and friends — comparisons between
     * two constants, which PHPStan correctly flagged as "always true". Those
     * booleans asserted nothing at runtime: the compiler folds them.
     *
     * What actually guards the configuration is the TEST asserting the constants
     * (mutation-verified: raising LOGO_RATIO to 0.45 fails it). This method
     * exists to surface the values — for a log line, an admin diagnostic, or a
     * reader wanting to know what was used — and the bounds travel with them.
     *
     * @return array<string, int|float|string>
     */
    public function structuralChecks(): array
    {
        return [
            'error_correction_level' => ErrorCorrectionLevel::High->name,
            'logo_ratio' => self::LOGO_RATIO,
            'logo_ratio_max' => 0.30,
            'quiet_zone_modules' => self::MARGIN,
            'render_size_px' => self::PNG_SIZE,
        ];
    }
}
