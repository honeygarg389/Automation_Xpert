<?php

namespace Tests\Feature\SmartQr;

use App\Modules\SmartQr\Services\SmartQrImageRenderer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The circular logo mask. §14.
 *
 * ─── ⚠️ WHY EVERY ASSERTION HERE READS PIXELS ───────────────────────────────
 *
 * The defect this file pins was invisible to the existing suite for the whole
 * life of the logo feature, and it was found by the owner looking at a printed
 * sticker. Nothing was broken in a way a structural test could see: the logo
 * WAS in the file, at the right size, in the right place. It simply carried its
 * own opaque white rectangle, which composited over the QR modules.
 *
 * `assertStringContainsString($logo, $svg)` passes on both the broken and the
 * fixed render. So does "an image came back", "it is a valid PNG", "the bytes
 * differ from the plain version" and every other proxy. This is the same trap
 * recorded three times already in CLAUDE.md — payload vs stored row, key vs
 * rendered label, bytes present vs pixels drawn. The artefact asserted on has
 * to be the artefact the user receives, so these tests decode the output and
 * read individual pixels.
 *
 * ─── ⚠️ THE CONTROL THAT MAKES THE CORNER ASSERTION MEAN ANYTHING ───────────
 *
 * "The corner is not white" is worthless on its own — the QR module underneath
 * a corner may legitimately BE white, and a render that dropped the logo
 * entirely would pass it. So the comparison is against THE SAME CODE RENDERED
 * WITH NO LOGO: outside the circle every pixel must equal the plain render
 * (proving nothing was painted over the QR), and inside it many must differ
 * (proving the logo is actually there). Neither half is sufficient alone.
 */
class SmartQrLogoMaskTest extends TestCase
{
    private const URL = 'https://example.test/q/MASKTEST';

    private const SERIAL = 'L-000001';

    /** Logo geometry in the rendered image. */
    private const CENTRE_X = 528.0;

    private const CENTRE_Y = 527.0;

    /** PNG_SIZE * LOGO_RATIO / 2 — the mask circle's radius on the canvas. */
    private const RADIUS = 112.5;

    /**
     * An OPAQUE square logo — a white field with a red centre, and no alpha
     * anywhere. This is not a contrived worst case: measured across the real
     * uploads on the owner's installation, 0.0% of pixels were transparent in
     * every one. A transparent-PNG fixture would make these tests pass without
     * the mask and prove nothing.
     */
    private function opaqueSquareLogoFile(int $size = 240): string
    {
        $image = imagecreatetruecolor($size, $size);
        $white = imagecolorallocate($image, 255, 255, 255);
        $red = imagecolorallocate($image, 220, 0, 0);
        imagefilledrectangle($image, 0, 0, $size - 1, $size - 1, $white);
        imagefilledrectangle($image, (int) ($size * 0.3), (int) ($size * 0.3), (int) ($size * 0.7), (int) ($size * 0.7), $red);

        $path = tempnam(sys_get_temp_dir(), 'masktest-src-').'.png';
        imagepng($image, $path);
        imagedestroy($image);

        return $path;
    }

    /** @return array{0: \GdImage, 1: \GdImage} plain render, logo render */
    private function renderPair(string $logoPath): array
    {
        $renderer = new SmartQrImageRenderer;

        return [
            imagecreatefromstring($renderer->png(self::URL, self::SERIAL)['data']),
            imagecreatefromstring($renderer->png(self::URL, self::SERIAL, $logoPath)['data']),
        ];
    }

    private function distanceFromCentre(int $x, int $y): float
    {
        return sqrt(($x - self::CENTRE_X) ** 2 + ($y - self::CENTRE_Y) ** 2);
    }

    /**
     * ⚠️ THE CENTRAL ASSERTION. Outside the circle the branded render must be
     * pixel-identical to the unbranded one.
     *
     * A tolerance of 6 px past the radius is allowed and is NOT a fudge to make
     * a failing test pass: the logo is resampled to 225 px from a source of a
     * different size, so the mask's hard edge lands as a soft one. Measured on
     * the five real uploads, the furthest differing pixel sat 4.23 px past the
     * radius, on a 51x51 source upscaled 4.4x. The defect this guards against
     * puts differing pixels at the BOX CORNERS, 159 px out — 37x beyond this
     * tolerance, so the band cannot hide it.
     */
    #[Test]
    public function nothing_outside_the_circle_is_painted_over_the_qr_code(): void
    {
        $logo = $this->opaqueSquareLogoFile();

        try {
            [$plain, $branded] = $this->renderPair($logo);

            $clobbered = [];

            for ($y = 380; $y <= 675; $y++) {
                for ($x = 380; $x <= 675; $x++) {
                    if ($this->distanceFromCentre($x, $y) <= self::RADIUS + 6) {
                        continue;
                    }

                    if (imagecolorat($branded, $x, $y) !== imagecolorat($plain, $x, $y)) {
                        $clobbered[] = sprintf('(%d,%d) d=%.1f', $x, $y, $this->distanceFromCentre($x, $y));
                    }
                }
            }

            $this->assertSame([], array_slice($clobbered, 0, 10),
                count($clobbered).' pixels outside the logo circle differ from the same code rendered '
                .'without a logo. The logo is painting over QR modules beyond its circular mask — which '
                .'is what an unmasked opaque rectangle does, and it reaches print.');
        } finally {
            @unlink($logo);
        }
    }

    /**
     * ⚠️ POSITIVE CONTROL for the test above. Without this, a render that
     * dropped the logo entirely would pass "nothing outside the circle differs"
     * perfectly, and the suite would report the mask working while the artwork
     * was blank.
     */
    #[Test]
    public function the_logo_artwork_is_still_drawn_inside_the_circle(): void
    {
        $logo = $this->opaqueSquareLogoFile();

        try {
            [$plain, $branded] = $this->renderPair($logo);

            $differing = 0;
            $total = 0;

            for ($y = 440; $y <= 615; $y++) {
                for ($x = 440; $x <= 615; $x++) {
                    if ($this->distanceFromCentre($x, $y) >= self::RADIUS - 8) {
                        continue;
                    }

                    $total++;

                    if (imagecolorat($branded, $x, $y) !== imagecolorat($plain, $x, $y)) {
                        $differing++;
                    }
                }
            }

            $this->assertGreaterThan(0, $total, 'The sampled region was empty — the geometry constants are wrong.');
            $this->assertGreaterThan($total * 0.5, $differing,
                'Fewer than half the pixels inside the circle differ from the plain render, so the logo '
                .'is largely absent. The mask must remove the square, not the artwork.');
        } finally {
            @unlink($logo);
        }
    }

    /**
     * The four corners of the logo's BOUNDING BOX — the exact pixels that were
     * white before the mask, and the ones a customer sees as a square.
     */
    #[Test]
    public function the_logo_bounding_box_corners_show_the_qr_code_not_a_white_square(): void
    {
        $logo = $this->opaqueSquareLogoFile();

        try {
            [$plain, $branded] = $this->renderPair($logo);

            $half = 112;

            foreach ([
                'top-left' => [-$half + 4, -$half + 4],
                'top-right' => [$half - 4, -$half + 4],
                'bottom-left' => [-$half + 4, $half - 4],
                'bottom-right' => [$half - 4, $half - 4],
            ] as $corner => [$dx, $dy]) {
                $x = (int) self::CENTRE_X + $dx;
                $y = (int) self::CENTRE_Y + $dy;

                $this->assertSame(
                    imagecolorat($plain, $x, $y),
                    imagecolorat($branded, $x, $y),
                    "The {$corner} corner of the logo box differs from the unbranded render, so the logo's "
                    .'own background is covering a QR module there. This is the square the mask exists to remove.'
                );
            }
        } finally {
            @unlink($logo);
        }
    }

    /**
     * ⚠️ THE SVG PATH IS A SEPARATE IMPLEMENTATION IN endroid AND MUST BE
     * ASSERTED SEPARATELY. Its writer ignores punchoutBackground entirely — it
     * base64s the logo file into an <image> element — so a fix verified only on
     * the raster path could leave SVG (and therefore PDF, which embeds the SVG)
     * still square. Decodes the embedded logo and reads its alpha channel.
     */
    #[Test]
    public function the_logo_embedded_in_the_svg_is_transparent_at_its_corners(): void
    {
        $logo = $this->opaqueSquareLogoFile();

        try {
            $svg = (new SmartQrImageRenderer)->svg(self::URL, self::SERIAL, $logo)['data'];

            $this->assertSame(1, preg_match('/href="data:image\/png;base64,([^"]+)"/', $svg, $matches),
                'The SVG carries no embedded PNG logo at all.');

            $embedded = imagecreatefromstring((string) base64_decode($matches[1], true));
            $this->assertNotFalse($embedded, 'The embedded logo data is not a decodable image.');

            $width = imagesx($embedded);
            $height = imagesy($embedded);
            $alpha = fn (int $x, int $y): int => (imagecolorat($embedded, $x, $y) >> 24) & 0x7F;

            foreach ([
                'top-left' => [2, 2],
                'top-right' => [$width - 3, 2],
                'bottom-left' => [2, $height - 3],
                'bottom-right' => [$width - 3, $height - 3],
            ] as $corner => [$x, $y]) {
                $this->assertSame(127, $alpha($x, $y),
                    "The embedded SVG logo's {$corner} corner is opaque. The SVG writer draws these bytes "
                    .'verbatim, so an opaque corner is a visible white square on every sticker and PDF.');
            }

            $this->assertSame(0, $alpha(intdiv($width, 2), intdiv($height, 2)),
                'The centre of the embedded logo is transparent — the mask has erased the artwork itself.');
        } finally {
            @unlink($logo);
        }
    }

    /**
     * ⚠️ THE UPLOAD IS THE ADMIN'S ONLY COPY. Masking is a render-time
     * transformation of a DERIVED file; if it ever mutated the source, an
     * admin's logo would be silently and irreversibly cropped to a circle on
     * first render, and no undo exists. Hashes the file rather than trusting
     * that the code "looks like" it writes elsewhere.
     */
    #[Test]
    public function rendering_never_modifies_the_uploaded_logo_file(): void
    {
        $logo = $this->opaqueSquareLogoFile();

        try {
            $before = sha1_file($logo);
            $sizeBefore = filesize($logo);

            $renderer = new SmartQrImageRenderer;
            $renderer->png(self::URL, self::SERIAL, $logo);
            $renderer->svg(self::URL, self::SERIAL, $logo);
            $renderer->pdf(self::URL, self::SERIAL, $logo);

            $this->assertSame($before, sha1_file($logo),
                'The uploaded logo file was modified by rendering. The mask must produce a derived copy.');
            $this->assertSame($sizeBefore, filesize($logo), 'The uploaded logo file changed size.');
        } finally {
            @unlink($logo);
        }
    }

    /**
     * ⚠️ THE MASK IS A TEMP FILE AND A 500-CODE EXPORT SHARES ONE RENDERER.
     * Pins both halves: the mask is built once and reused (not rebuilt per
     * code), and it is deleted when the renderer goes out of scope. A per-code
     * rebuild would be a full-image pixel loop 500 times over for a
     * byte-identical result; a missing cleanup would fill /tmp on a worker that
     * renders batch after batch.
     */
    #[Test]
    public function the_mask_is_cached_per_renderer_and_cleaned_up_afterwards(): void
    {
        $logo = $this->opaqueSquareLogoFile();
        $count = fn (): int => count(glob(sys_get_temp_dir().'/smartqr-logo-*') ?: []);

        try {
            $before = $count();

            $renderer = new SmartQrImageRenderer;

            for ($i = 0; $i < 5; $i++) {
                $renderer->png(self::URL, self::SERIAL, $logo);
            }

            $this->assertSame($before + 1, $count(),
                'Five renders with the same logo produced more than one mask file. The mask is being '
                .'rebuilt per code — a full-image pixel loop repeated for every code in the export.');

            unset($renderer);
            gc_collect_cycles();

            $this->assertSame($before, $count(),
                'The mask temp file outlived the renderer. A worker rendering many batches would '
                .'accumulate one of these per logo until the volume filled.');
        } finally {
            @unlink($logo);
        }
    }

    /**
     * ⚠️ AN UNREADABLE LOGO THROWS, AND THAT IS THE INTENDED CONTRACT — pinned
     * here because the obvious "improvement" is to swallow it.
     *
     * When masking cannot read the file the renderer falls back to the original
     * path, and endroid rejects it there. The alternative — quietly dropping the
     * logo — would render the whole batch UNBRANDED and succeed, so an admin
     * would download 500 finished stickers with no way to tell anything went
     * wrong until they came back from the printer. A thrown exception fails the
     * export job, which records FAILED against the row the admin is watching.
     *
     * The graceful-degradation path is for a mask that fails on a VALID image
     * (no temp space, GD refusing a large allocation): there the original is
     * usable, and a square logo beats no export. That case is a Log::warning,
     * not a throw.
     */
    #[Test]
    public function an_unreadable_logo_fails_loudly_rather_than_rendering_unbranded(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'masktest-bad-');
        file_put_contents($path, 'this is not an image');

        try {
            $this->expectException(\Throwable::class);

            (new SmartQrImageRenderer)->png(self::URL, self::SERIAL, $path);
        } finally {
            @unlink($path);
        }
    }
}
