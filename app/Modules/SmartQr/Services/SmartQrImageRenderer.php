<?php

namespace App\Modules\SmartQr\Services;

use App\Modules\SmartQr\Models\SmartQrBatch;
use Barryvdh\DomPDF\Facade\Pdf;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Label\Font\OpenSans;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Writer\Result\ResultInterface;
use Endroid\QrCode\Writer\SvgWriter;
use Illuminate\Support\Facades\Log;
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
 *     a path whose output then gets PRINTED.
 *
 * ⚠️ THAT SECOND POINT IS NOW ONLY HALF TRUE, AND THE HALF THAT CHANGED IS
 * NAMED HERE RATHER THAN LEFT AS A STALE COMMENT. A per-BATCH logo upload does
 * exist (an admin-only control on batch creation, not a customer-facing
 * designer, so R-2's colour/pattern ruling is untouched). The vulnerability is
 * therefore MITIGATED rather than avoided, on three layers:
 *
 *     1. `mimes:png,jpg,jpeg` behind `file` — not `image`, which would override
 *        the allow-list. See StoreQrBatchRequest.
 *     2. SafeUploadExtension sniffs the content for the STORED extension.
 *     3. The private `local` disk: nothing serves these to a browser, and
 *        batchLogoPath() refuses SVG before GD ever sees it.
 *
 * Everything else below — colour, pattern, finder shape, error correction,
 * margin, size — remains fixed and unexposed.
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
     * ⚠️ Height of the serial band appended to the SVG, in px. Matches what the
     * PNG writer produces (1056 wide -> 1096 tall), and it must keep matching:
     * endroid derives the PNG band itself as `label bbox height + 10px bottom
     * margin`, so raising the PNG label size moves that number and this constant
     * has to follow or the two formats stop being the same shape.
     */
    private const SVG_LABEL_BAND = 40;

    /**
     * Masked logo temp files, keyed by source path + mtime + size.
     *
     * @var array<string, string>
     */
    private array $maskedLogos = [];

    /**
     * ⚠️ MEASURED ZIPPED BYTES PER CODE. Not a formula, not an estimate derived
     * from image dimensions — actual ZipArchive output, because a ZIP is what
     * the admin downloads and it is the only number that predicts their wait.
     *
     *   |          | SVG/code | PNG/code | PDF/code | 500 SVG | 500 PNG | 500 PDF |
     *   |----------|----------|----------|----------|---------|---------|---------|
     *   | no logo  |   4.5 KB |   6.8 KB |   4.9 KB |  2.1 MB |  3.2 MB |  2.4 MB |
     *   | logo     |  53.7 KB |  41.0 KB |  49.0 KB |   26 MB |   20 MB |   23 MB |
     *
     * ⚠️ THE LOGO ROW IS A WORST CASE ACROSS REAL UPLOADS, NOT A TYPICAL ONE,
     * and the spread is wide enough that a single figure cannot be honest about
     * it. Measured over the five logos actually uploaded on this installation,
     * per code, SVG ranged 7.2 KB (51x51 source) to 53.7 KB (319x319). The
     * larger end is stored deliberately: over-estimating a download is a mild
     * surprise, under-estimating it is an admin cancelling a transfer that was
     * nearly done.
     *
     * ⚠️ PDF tracks SVG, not PNG, because print.blade.php embeds the SVG as a
     * base64 data URI — so it inherits the SVG's logo cost exactly.
     *
     * ⚠️ THE LOGO FIGURES ARE POST-MASK AND THE PRE-MASK ONES WERE 3.7-9.9x TOO
     * HIGH. They were measured when the logo reached endroid as the admin's
     * uploaded bytes. It now arrives as a GD re-encode (see
     * circularlyMaskedLogo), which drops the XMP metadata block Canva-style
     * exports carry — several hundred KB, base64'd into EVERY SVG. The old
     * `svg => 491_000` told an admin a 500-code export was 234 MB when it
     * renders at about 26 MB.
     *
     * ⚠️ DO NOT RESTORE THE CLAIM THAT SVG IS "ROUGHLY 3x LARGER THAN PNG".
     * That held pre-mask and no longer generalises — it now depends on the
     * source logo's size, and INVERTS for small ones:
     *
     *   51x51   source -> SVG  7.2 KB vs PNG 21.9 KB  (SVG ~3x SMALLER)
     *   319x319 source -> SVG 53.7 KB vs PNG 37.1 KB  (SVG ~1.4x larger)
     *
     * The mechanism is unchanged — SvgWriter base64s the logo into every file
     * while the PNG writer rasterises it once — but the logo is now small
     * enough that the QR's own raster cost dominates at small sizes.
     *
     * SVG REMAINS THE DEFAULT, and on the argument that always actually held:
     * it is vector and prints crisply at any physical size, where a 1024 px PNG
     * blurs on anything larger than a sticker. Size was never the real reason,
     * and it is now not even reliably in SVG's favour.
     *
     * These figures drive the UI note, so an admin sees the true cost of the
     * format they pick. ⚠️ RE-MEASURE IF THE LOGO PIPELINE CHANGES AGAIN — that
     * instruction is why this staleness was caught rather than shipped, and it
     * earns its place by having already paid off once.
     */
    public const ZIPPED_BYTES_PER_CODE = [
        'no_logo' => ['svg' => 4_495, 'png' => 6_809, 'pdf' => 4_984],
        'logo' => ['svg' => 53_702, 'png' => 41_041, 'pdf' => 49_011],
    ];

    /**
     * Bytes per code for a given logo state.
     *
     * ⚠️ A HINT FOR THE EXPORT UI, NOT A BILLING FIGURE — and now that a logo
     * belongs to a BATCH rather than to the installation, a selection spanning
     * several batches can genuinely mix both branches. Deliberately not modelled
     * exactly: the caller passes true if ANY batch in scope carries a logo, so
     * a mixed selection is OVER-estimated. Over-estimating a wait is a mild
     * surprise; under-estimating it is an admin abandoning a download that was
     * about to finish.
     *
     * ⚠️ THE PARAMETER REPLACED A `logoPath()` CALL. This used to consult the
     * platform logo, and it must not: the platform logo is no longer a Smart QR
     * input in any form. See batchLogoPath().
     *
     * @return array{svg: int, png: int, pdf: int}
     */
    public function zippedBytesPerCode(bool $hasLogo = false): array
    {
        return self::ZIPPED_BYTES_PER_CODE[$hasLogo ? 'logo' : 'no_logo'];
    }

    /**
     * @param  string|null  $logoPath  Absolute path to the batch's logo, or null
     *                                 for a PLAIN render. Resolve it with
     *                                 batchLogoPath() — never by reading a
     *                                 stored column directly.
     * @return array{data: string, mime: string}
     */
    public function svg(string $url, string $serial, ?string $logoPath = null): array
    {
        $result = $this->build($url, $serial, new SvgWriter, $logoPath);

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
     *     PNG -> 1056 x 1096   (taller: the serial band is rendered)
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
        // ⚠️ MATCH THE WHOLE OPENING TAG, up to and including the '>'.
        //
        // The original pattern stopped at height="…", and in endroid's output the
        // viewBox comes AFTER height — so the viewBox sat OUTSIDE the matched
        // span and the replacement below was a silent no-op. The height grew to
        // 1096 while the viewBox stayed 0 0 1056 1056, which put the serial band
        // outside the viewport: present in the file, invisible in every renderer,
        // and inherited by the PDF because pdf() embeds this same SVG.
        //
        // ⚠️ That shipped. The slice-8 test asserted the serial STRING was in the
        // file, which it always was. Bytes present is not pixels drawn — the same
        // trap as asserting a dispatched payload instead of the stored row.
        if (! preg_match('/<svg\b[^>]*>/', $svg, $m)
            || ! preg_match('/\bwidth="(\d+)px"/', $m[0], $w)
            || ! preg_match('/\bheight="(\d+)px"/', $m[0], $h)) {
            // Shape changed under us — return the QR unlabelled rather than a
            // corrupted file. The test below fails loudly if this ever happens.
            return $svg;
        }

        [$full, $width, $height] = [$m[0], (int) $w[1], (int) $h[1]];
        $newHeight = $height + self::SVG_LABEL_BAND;

        $tag = preg_replace(
            '/\bheight="'.$height.'px"/',
            'height="'.$newHeight.'px"',
            $full,
            1
        );

        // The viewBox must grow with the canvas or the band is clipped. Rewritten
        // by pattern rather than by exact string, because its value is only
        // predictable while endroid keeps emitting "0 0 W H".
        if (preg_match('/\bviewBox="\s*0\s+0\s+'.$width.'\s+'.$height.'\s*"/', $tag)) {
            $tag = preg_replace(
                '/\bviewBox="\s*0\s+0\s+'.$width.'\s+'.$height.'\s*"/',
                'viewBox="0 0 '.$width.' '.$newHeight.'"',
                $tag,
                1
            );
        } elseif (! str_contains($tag, 'viewBox')) {
            // No viewBox at all: add one, so the band is inside the viewport.
            $tag = substr($tag, 0, -1).' viewBox="0 0 '.$width.' '.$newHeight.'">';
        }

        $svg = str_replace($full, $tag, $svg);

        $text = sprintf(
            '<rect x="0" y="%d" width="%d" height="%d" fill="#ffffff"/>'
            .'<text x="%d" y="%d" font-family="sans-serif" font-size="39.5" fill="#000000" '
            .'text-anchor="middle">S.No: %s</text>',
            $height, $width, self::SVG_LABEL_BAND,
            (int) ($width / 2), $height + 30,
            htmlspecialchars($serial, ENT_QUOTES | ENT_XML1)
        );

        return str_replace('</svg>', $text.'</svg>', $svg);
    }

    /** @return array{data: string, mime: string} */
    public function png(string $url, string $serial, ?string $logoPath = null): array
    {
        $result = $this->build($url, $serial, new PngWriter, $logoPath);

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
    public function pdf(string $url, string $serial, ?string $logoPath = null): array
    {
        // ⚠️ THE LOGO MUST BE THREADED THROUGH THIS CALL. The PDF is the SVG
        // embedded as a data URI, so dropping the argument here would produce a
        // branded SVG download and an unbranded PDF from the same code — a
        // difference nobody sees until both are printed side by side.
        $svg = $this->svg($url, $serial, $logoPath)['data'];

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
    private function build(
        string $url,
        string $serial,
        SvgWriter|PngWriter $writer,
        ?string $logoPath = null,
    ): ResultInterface {
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
            ->labelFont(new OpenSans(30));

        // ⚠️ NO FALLBACK. The logo is whatever the caller passed, and null
        // means PLAIN. There is deliberately no `?? $this->somethingGlobal()`
        // here — see batchLogoPath() for why that absence is the feature.
        if ($logoPath !== null) {
            $builder = $builder
                ->logoPath($this->circularlyMaskedLogo($logoPath))
                ->logoResizeToWidth((int) round(self::PNG_SIZE * self::LOGO_RATIO))

                // ⚠️ FALSE, AND THE MASK IS WHY — do not "restore" this to true.
                //
                // endroid's punchout clears a RECTANGLE the full width and
                // height of the logo (AbstractGdWriter::addLogo, nested for
                // loops over getWidth() x getHeight()), which is precisely the
                // white square this mask exists to remove. Leaving it on
                // reinstates the square OUTSIDE our circle, on the raster path
                // only, so SVG and PNG would disagree.
                //
                // Measured, comparing each render against the same code with no
                // logo, over the 256 px box around the centre:
                //
                //   punchout=true  -> 4072 of 23607 pixels outside the circle
                //                     differ from the plain render (clobbered)
                //   punchout=false ->    0 of 23607 differ (QR fully intact)
                //
                // With the corners already transparent, GD blends the logo over
                // the modules and the punchout has nothing left to do but
                // damage. See SmartQrLogoMaskTest.
                ->logoPunchoutBackground(false);
        }

        return $builder->build();
    }

    /**
     * ═══ ⚠️ THE LOGO IS THE BATCH'S, AND THERE IS NO FALLBACK ═════════════
     *
     * §14 said "use the AutomationXpert logo", and this used to resolve the
     * configured PLATFORM logo (`app_logo_path`) for every render. It no longer
     * does, and the platform logo is not consulted here in ANY form — not as a
     * default, not as a fallback when a batch has none.
     *
     * ⚠️ THAT IS THE POINT, NOT AN OVERSIGHT. A print run is a physical,
     * irreversible artefact. If a batch created with no logo silently inherited
     * whatever brand the installation happens to be configured with, the way it
     * is discovered is a box of five hundred stickers carrying the wrong mark —
     * and BUG-038 records that the only logo assets in this repository are
     * WhatsMine-branded, inherited from the original import. A plain QR is
     * honest. A competitor's brand printed onto a customer's stickers is not
     * recoverable once the run is done.
     *
     * So: batch has a logo -> that logo. Batch has none, or no batch at all ->
     * null -> PLAIN. Full stop.
     *
     * ⚠️ SEC-004 DISCIPLINE, UNCHANGED FROM THE PLATFORM-LOGO VERSION:
     *
     *   - `$disk->exists()` before resolving, so a deleted file is "no logo"
     *     rather than a render-time explosion 500 codes into a queued export.
     *   - `is_file()` on the resolved absolute path — `$disk->path()` is a
     *     string operation and asserts nothing about the filesystem.
     *   - SVG is REFUSED. GD cannot rasterise it, so a PNG render would fail at
     *     output rather than here, and it keeps this path out of SEC-004's
     *     territory entirely. The extension is checked as a backstop only: what
     *     actually keeps SVG off this disk is SafeUploadExtension, which sniffs
     *     the content at upload and stores anything denied or unrecognised as
     *     `.bin` — which then fails the check below on its own.
     *
     * ⚠️ `$disk->path()` EXISTS ONLY ON LOCAL-DRIVER DISKS. Batch logos are
     * written to `local` for exactly this reason (QrBatchController). A row
     * naming an s3-family disk resolves to null here rather than throwing —
     * a plain render, not a 500.
     */
    public function batchLogoPath(?SmartQrBatch $batch): ?string
    {
        $path = $batch?->logo_path;

        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        try {
            $disk = Storage::disk($batch->logo_disk ?: 'local');

            if (! $disk->exists($path)) {
                return null;
            }

            $absolute = $disk->path($path);
        } catch (\Throwable) {
            // Unknown disk name, or a driver with no local path. Plain, not fatal.
            return null;
        }

        if (! is_file($absolute) || strtolower(pathinfo($absolute, PATHINFO_EXTENSION)) === 'svg') {
            return null;
        }

        return $absolute;
    }

    /**
     * A copy of the batch logo with everything outside the inscribed circle made
     * fully transparent. Returns the ORIGINAL path unchanged if masking fails.
     *
     * ═══ ⚠️ WHY THIS EXISTS AT ALL ═══════════════════════════════════════════
     *
     * Every logo an admin uploads in practice is an opaque rectangle — a mark on
     * a white field, exported from Canva or similar. Measured across the three
     * real uploads on this installation: 0.0% transparent pixels in all three.
     *
     * So the white square behind the centre logo was never drawn by this class.
     * It is the logo file's own background, composited verbatim, and no amount
     * of configuration on endroid's builder removes it — the bytes are opaque.
     * The fix has to change the PIXELS, which is what this does.
     *
     * ⚠️ THE ORIGINAL UPLOAD IS NEVER MODIFIED. This writes a derived temp file
     * and hands endroid that instead. An admin who re-downloads their logo, or
     * whose batch is later rendered by different code, still has the file they
     * uploaded. Masking in place would be irreversible and would silently
     * destroy artwork on any future change of mind about the shape.
     *
     * ⚠️ ONE MASK PER SOURCE FILE PER INSTANCE, not per code. A 500-code export
     * shares one renderer, and the mask is a per-pixel loop over the full source
     * image — re-running it for every code would multiply that by 500 for a
     * byte-identical result. Keyed by path + mtime + size so a re-uploaded logo
     * at the same path is not served from a stale mask.
     *
     * ⚠️ COVERS ALL THREE FORMATS THROUGH ONE CHANGE, because both writers read
     * this same file: the PNG path re-parses its bytes with imagecreatefromstring
     * and composites them, and the SVG path base64s those same bytes into a data
     * URI (LogoImageData::createDataUri). PDF embeds the SVG, so it follows.
     * There is deliberately no second implementation to keep in step.
     *
     * ⚠️ A data:// URI CANNOT REPLACE THE TEMP FILE, though it looks like it
     * should. LogoImageData runs the path through filter_var(FILTER_VALIDATE_URL),
     * which ACCEPTS a data URI, and then resolves its mime type with
     * get_headers() — which cannot fetch one. Verified before writing this.
     */
    private function circularlyMaskedLogo(string $logoPath): string
    {
        $key = $logoPath.'|'.(@filemtime($logoPath) ?: 0).'|'.(@filesize($logoPath) ?: 0);

        if (isset($this->maskedLogos[$key])) {
            return $this->maskedLogos[$key];
        }

        $masked = $this->writeCircularMask($logoPath);

        if ($masked === null) {
            // ⚠️ FALLS BACK TO THE ORIGINAL, WHICH IS NOT THE SAME AS
            // "never fails". If the mask failed because the file is unreadable,
            // endroid rejects the original too and the export fails loudly —
            // deliberately, because the alternative is 500 silently unbranded
            // stickers discovered after printing. See SmartQrLogoMaskTest.
            //
            // The case this fallback actually serves is a mask that fails on a
            // VALID image (no temp space, a refused allocation): the original
            // still renders, square but branded. Logged so that shows up as a
            // cause rather than as "the mask sometimes does not work".
            Log::warning('smart_qr.logo_mask_failed', ['path' => $logoPath]);

            return $logoPath;
        }

        return $this->maskedLogos[$key] = $masked;
    }

    /**
     * The pixel work. Null on any GD failure — the caller decides what that means.
     */
    private function writeCircularMask(string $logoPath): ?string
    {
        $raw = @file_get_contents($logoPath);

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $source = @imagecreatefromstring($raw);

        if ($source === false) {
            return null;
        }

        try {
            $width = imagesx($source);
            $height = imagesy($source);

            $canvas = imagecreatetruecolor($width, $height);

            // ⚠️ BOTH CALLS ARE LOAD-BEARING. alphablending(false) makes writes
            // REPLACE the destination pixel instead of compositing onto it, so a
            // transparent pixel actually lands as transparent; savealpha(true)
            // makes imagepng() encode the alpha channel at all. Without the
            // second, the file looks right in memory and is written opaque.
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);

            $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);

            if ($transparent === false) {
                return null;
            }

            imagefilledrectangle($canvas, 0, 0, $width - 1, $height - 1, $transparent);

            // The largest circle that fits, centred — matching how endroid
            // centres the logo on the matrix.
            $centreX = ($width - 1) / 2;
            $centreY = ($height - 1) / 2;
            $radius = min($width, $height) / 2;
            $radiusSquared = $radius * $radius;

            for ($y = 0; $y < $height; $y++) {
                $dy = ($y - $centreY) ** 2;

                for ($x = 0; $x < $width; $x++) {
                    if ((($x - $centreX) ** 2 + $dy) <= $radiusSquared) {
                        // Copied WITH its alpha, so a logo that already had
                        // transparency keeps it inside the circle.
                        imagesetpixel($canvas, $x, $y, imagecolorat($source, $x, $y));
                    }
                }
            }

            $target = tempnam(sys_get_temp_dir(), 'smartqr-logo-');

            if ($target === false) {
                return null;
            }

            if (! imagepng($canvas, $target)) {
                @unlink($target);

                return null;
            }

            return $target;
        } catch (\Throwable) {
            return null;
        }

        // ⚠️ NO imagedestroy() HERE, DELIBERATELY. It was called on both handles
        // in a finally block; PHP 8.0 made GdImage a normal refcounted object, so
        // the calls have been no-ops since, and PHP 8.5 emits E_DEPRECATED for
        // them. Both images are freed when they fall out of scope.
    }

    /**
     * ⚠️ THE MASKS ARE TEMP FILES AND SOMETHING HAS TO DELETE THEM. One per
     * batch logo per renderer instance is small, but an export worker rendering
     * batch after batch would accumulate them for the life of the process.
     * SmartQrImageExportTest already counts sys_get_temp_dir()/smartqr* as a
     * leak; these are named to fall under that same check rather than to dodge it.
     */
    public function __destruct()
    {
        foreach ($this->maskedLogos as $path) {
            @unlink($path);
        }

        $this->maskedLogos = [];
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
