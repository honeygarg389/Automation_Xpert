<?php

namespace Tests\Feature\SmartQr;

use App\Models\AdminUser;
use App\Models\Permission;
use App\Models\Plan;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use App\Modules\SmartQr\Jobs\GenerateQrExportJob;
use App\Modules\SmartQr\Models\SmartQrCode;
use App\Modules\SmartQr\Services\SmartQrImageRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Slice 8 — §13/§14 image generation and export. The last slice.
 *
 * ─── ⚠️ THE FOUR THIS FILE EXISTS FOR ───────────────────────────────────────
 *
 *   the ZIP cap        501 codes is refused with a message, not by timeout
 *   failure cleanup    a half-built archive is DELETED, never left on disk
 *   no-logo fallback   renders plain; NEVER the WhatsMine asset
 *   structural checks  Level H, logo under tolerance, quiet zone — and the
 *                      claim is structural, not "validated readability"
 *
 * Plus the conversion proof: the module matrix must survive Dompdf.
 */
class SmartQrImageExportTest extends TestCase
{
    use RefreshDatabase;

    private function renderer(): SmartQrImageRenderer
    {
        return app(SmartQrImageRenderer::class);
    }

    private function adminWith(array $keys): AdminUser
    {
        $admin = AdminUser::factory()->create();
        $role = Role::firstOrCreate(
            ['key' => 'qr-img-'.(implode('-', $keys) ?: 'none')],
            ['name' => 'QR Image Test Role', 'description' => 'test']
        );

        foreach ($keys as $key) {
            $perm = Permission::firstOrCreate(
                ['key' => $key],
                ['name' => ucwords(str_replace('_', ' ', $key)), 'category' => 'QR Management']
            );
            $role->permissions()->syncWithoutDetaching([$perm->id]);
        }

        $admin->roles()->syncWithoutDetaching([$role->id]);

        return $admin->fresh();
    }

    // ══ The three formats ══════════════════════════════════════════════════

    #[Test]
    public function it_renders_svg_png_and_pdf(): void
    {
        $r = $this->renderer();

        $svg = $r->svg('https://x.test/q/abc', 'AX-000001');
        $this->assertStringContainsString('<svg', $svg['data']);
        $this->assertSame('image/svg+xml', $svg['mime']);

        $png = $r->png('https://x.test/q/abc', 'AX-000001');
        $this->assertStringStartsWith("\x89PNG", $png['data'], 'Not a valid PNG.');

        $pdf = $r->pdf('https://x.test/q/abc', 'AX-000001');
        $this->assertStringStartsWith('%PDF', $pdf['data'], 'Not a valid PDF.');
    }

    /**
     * ⚠️ §14 requires the serial BENEATH the QR, and endroid's SvgWriter
     * accepts a label then silently discards it.
     *
     * Measured: same builder, PNG comes out 1056x1094 (band rendered), SVG
     * 1056x1056 (square, no serial). Since SVG is the ZIP default and the ZIP
     * goes to a printer, shipping this unnoticed means 500 stickers with no
     * human-readable identifier.
     */
    #[Test]
    public function the_svg_carries_the_serial_beneath_the_code(): void
    {
        $svg = $this->renderer()->svg('https://x.test/q/abc', 'AX-000042')['data'];

        $this->assertStringContainsString('AX-000042', $svg,
            'The SVG has no serial. endroid\'s SvgWriter drops the label silently, so it has to '
            .'be appended — and §14 requires it on the artwork.');
        $this->assertNotFalse(simplexml_load_string($svg), 'The SVG is not well-formed XML.');
    }

    /**
     * ⚠️ AND THE BAND MUST BE INSIDE THE VIEWPORT. This is the assertion whose
     * absence let a broken sticker reach the owner.
     *
     * The test above passes on an SVG nobody can read the serial on: the text
     * element is in the file, so assertStringContainsString is satisfied, while
     * the viewBox still describes the ORIGINAL square. Everything below y=1056
     * is outside the viewport and no renderer draws it — browser, printer or
     * Dompdf.
     *
     * The cause was a silent no-op: the tag was matched with a pattern that
     * stopped at height="…", and endroid emits viewBox AFTER height, so the
     * viewBox was never part of the string being rewritten. The height grew,
     * the viewBox did not.
     *
     * ⚠️ Bytes present is not pixels drawn. This is the visual form of the trap
     * already recorded twice in CLAUDE.md — assert the stored row, not the
     * dispatched payload; assert the rendered label, not the translation key.
     */
    #[Test]
    public function the_serial_band_is_inside_the_svg_viewport_and_not_clipped(): void
    {
        $svg = $this->renderer()->svg('https://x.test/q/abc', 'AX-000042')['data'];

        $this->assertMatchesRegularExpression('/<svg\b[^>]*>/', $svg);
        preg_match('/<svg\b[^>]*>/', $svg, $tag);

        $this->assertMatchesRegularExpression('/\bviewBox="0 0 (\d+) (\d+)"/', $tag[0],
            'The SVG lost its viewBox entirely — it would scale unpredictably in print.');
        preg_match('/\bviewBox="0 0 (\d+) (\d+)"/', $tag[0], $box);
        preg_match('/\bheight="(\d+)px"/', $tag[0], $canvas);

        $this->assertSame($canvas[1], $box[2],
            'The canvas height and the viewBox height disagree. When the serial band was '
            .'appended the canvas grew and the viewBox did not, so the band is drawn outside '
            .'the viewport: present in the file, invisible on the sticker and in the PDF.');

        // The serial text must sit within the viewBox, not merely exist.
        $this->assertMatchesRegularExpression('/<text[^>]*\by="(\d+)"/', $svg);
        preg_match('/<text[^>]*\by="(\d+)"/', $svg, $text);

        $this->assertLessThanOrEqual((int) $box[2], (int) $text[1],
            'The serial text is below the bottom of the viewBox and will not render.');
        $this->assertGreaterThan((int) $box[2] - self::BAND_TOLERANCE, (int) $text[1],
            'Positive control: the serial should sit in the band at the BOTTOM, not floating '
            .'somewhere in the middle of the QR where it would obscure modules.');
    }

    /** Height of the appended band, plus room for the baseline offset. */
    private const BAND_TOLERANCE = 60;

    // ══ ⚠️ RETRIEVAL — the half that was missing entirely ══════════════════

    /**
     * ⚠️ A BUILT ARCHIVE MUST BE REACHABLE.
     *
     * The job wrote a valid ZIP to storage and the flash said it would "appear
     * in storage when ready" — with no route, no link and no listing. Four real
     * archives sat unreachable on the owner's machine. An export whose output
     * cannot be retrieved is not an export.
     */
    #[Test]
    public function a_finished_export_can_be_listed_and_downloaded(): void
    {
        // ⚠️ FAKE THE DISK. Without this the assertions read the developer's
        // REAL storage/app/private, which already held four archives — the
        // test failed by counting them, and worse, it WROTE there too.
        Storage::fake('local');

        $admin = $this->adminWith(['view_qr_inventory']);

        Storage::disk('local')->put('smartqr-exports/20260819-101010-abcdef01.zip', 'PK-fake-zip');

        $this->actingAs($admin, 'admin')
            ->get(route('admin.qr.inventory.index'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->has('readyExports', 1)
                ->where('readyExports.0.name', '20260819-101010-abcdef01.zip'));

        $this->actingAs($admin, 'admin')
            ->get(route('admin.qr.inventory.export-download', '20260819-101010-abcdef01.zip'))
            ->assertOk()
            ->assertDownload('20260819-101010-abcdef01.zip');
    }

    /**
     * ⚠️ The filename is a route parameter interpolated into a storage path.
     *
     * Traversal is the obvious attack, and the guard is that the request must
     * match a file we already listed — not a string check that a future edit
     * could weaken.
     */
    #[Test]
    public function the_export_download_refuses_a_path_outside_the_export_directory(): void
    {
        // ⚠️ FAKE THE DISK. Without this the assertions read the developer's
        // REAL storage/app/private, which already held four archives — the
        // test failed by counting them, and worse, it WROTE there too.
        Storage::fake('local');

        $admin = $this->adminWith(['view_qr_inventory']);

        Storage::disk('local')->put('secrets.txt', 'not yours');
        Storage::disk('local')->put('smartqr-exports/real.zip', 'PK-fake-zip');

        $this->actingAs($admin, 'admin')
            ->get(route('admin.qr.inventory.export-download', '../secrets.txt'))
            ->assertNotFound();

        // POSITIVE CONTROL: the same route, same verb, same admin, succeeds for
        // a legitimate name — so the 404 above is the guard, not a dead route.
        $this->actingAs($admin, 'admin')
            ->get(route('admin.qr.inventory.export-download', 'real.zip'))
            ->assertOk();
    }

    /**
     * ⚠️ PDF was withheld from the bulk export on an unmeasured assumption that
     * Dompdf would be too slow. Measured at 0.04 s and ~5 KB zipped per code, it
     * is cheaper than the PNG it sat beside.
     */
    #[Test]
    public function the_export_accepts_pdf_and_writes_pdf_entries(): void
    {
        // ⚠️ FAKE THE DISK. Without this the assertions read the developer's
        // REAL storage/app/private, which already held four archives — the
        // test failed by counting them, and worse, it WROTE there too.
        Storage::fake('local');

        $this->assertContains('pdf', GenerateQrExportJob::FORMATS,
            'PDF is the format a print shop asks for; withholding it was an assumption, not a limit.');

        $code = SmartQrCode::factory()->create(['serial_number' => 'AX-PDF-1']);

        (new GenerateQrExportJob([$code->id], 'pdf'))->handle($this->renderer());

        $files = Storage::disk('local')->files('smartqr-exports');
        $this->assertCount(1, $files);

        $zip = new \ZipArchive;
        $zip->open(Storage::disk('local')->path($files[0]));

        $this->assertSame('AX-PDF-1.pdf', $zip->getNameIndex(0),
            'The archive entry must carry the .pdf extension, or the print shop gets a file '
            .'their tooling will not open.');
        $this->assertStringStartsWith('%PDF', (string) $zip->getFromIndex(0),
            'The entry is not a real PDF.');
        $zip->close();
    }

    // ══ ⚠️ THE DOMPDF CONVERSION PROOF ═════════════════════════════════════

    /**
     * ⚠️ THE MODULE MATRIX MUST SURVIVE THE PDF CONVERSION.
     *
     * The SVG goes through `dompdf/php-svg-lib`, and a PDF whose QR is subtly
     * wrong prints before anyone notices. So this is proven, not assumed.
     *
     * The discriminator: two DIFFERENT codes must produce DIFFERENT PDFs. If
     * the matrix were dropped, both would render the same empty frame and the
     * outputs would be identical — which is exactly what happens with an inline
     * <svg>, measured at ~1,140 bytes against ~5,300 for a rendered one.
     */
    #[Test]
    public function the_module_matrix_survives_the_pdf_conversion(): void
    {
        $r = $this->renderer();

        $a = $r->pdf('https://x.test/q/aaaaaaaaaaaaaaaa', 'AX-000001')['data'];
        $b = $r->pdf('https://x.test/q/bbbbbbbbbbbbbbbb', 'AX-000002')['data'];

        $this->assertNotSame($a, $b,
            'Two different QR codes produced byte-identical PDFs. The module matrix was dropped '
            .'in conversion and both pages are the same empty frame — a PDF that prints before '
            .'anyone notices the QR is wrong.');

        // …and neither is the degenerate empty page.
        $this->assertGreaterThan(2000, strlen($a),
            'The PDF is close to the empty-page size, so the QR did not render at all.');
    }

    // ══ ⚠️ THE NO-LOGO FALLBACK ════════════════════════════════════════════

    /**
     * ⚠️ With no logo configured, the QR renders PLAIN — never the WhatsMine
     * asset that ships in `public/`.
     *
     * A plain QR is honest. A competitor's brand printed onto a customer's
     * stickers is not recoverable once the run is done.
     */
    #[Test]
    public function no_configured_logo_renders_plain_and_never_the_inherited_asset(): void
    {
        SystemSetting::query()->where('key', 'app_logo_path')->delete();

        $this->assertNull($this->renderer()->logoPath(),
            'A logo path was resolved with none configured. The only logo files in this repo are '
            .'WhatsMine-branded, and printing those onto a customer\'s stickers is irreversible.');

        // The render still succeeds — plain, not broken.
        $svg = $this->renderer()->svg('https://x.test/q/abc', 'AX-000001');
        $this->assertStringContainsString('<svg', $svg['data']);
    }

    /** …and an SVG logo is refused: GD cannot rasterise it, and SEC-004 lives there. */
    #[Test]
    public function an_svg_logo_is_refused(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('brand/logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');

        SystemSetting::updateOrCreate(['key' => 'app_logo_path'], ['value' => 'brand/logo.svg']);
        SystemSetting::updateOrCreate(['key' => 'app_logo_disk'], ['value' => 'public']);

        $this->assertNull($this->renderer()->logoPath(),
            'An SVG was accepted as a logo source. GD cannot rasterise it, so PNG output would '
            .'fail at render time — and it walks this path into SEC-004\'s territory.');
    }

    // ══ ⚠️ STRUCTURAL CHECKS — NOT "VALIDATED READABILITY" ═════════════════

    /**
     * ⚠️ §14 says "validate scan readability". This asserts the STRUCTURAL
     * properties that make a code readable, and claims nothing more.
     *
     * Actually decoding would need a QR reader — bacon is an encoder only — and
     * avoiding a second library was R-6's entire argument.
     */
    #[Test]
    public function the_structural_readability_properties_hold(): void
    {
        // ⚠️ The CONSTANTS are what this asserts. structuralChecks() reports
        // values; asserting its booleans would be asserting a comparison between
        // two constants, which is always true and guards nothing.
        $this->assertLessThan(0.30, SmartQrImageRenderer::LOGO_RATIO,
            'The logo covers more than Level H\'s ~30% damage tolerance, so a scuffed sticker '
            .'stops decoding.');
        $this->assertGreaterThan(0, SmartQrImageRenderer::MARGIN, '§14 requires a quiet zone.');

        $checks = $this->renderer()->structuralChecks();
        $this->assertSame('High', $checks['error_correction_level'], '§14 requires Level H.');
        $this->assertSame(SmartQrImageRenderer::LOGO_RATIO, $checks['logo_ratio']);
    }

    // ══ ⚠️ THE ZIP CAP ═════════════════════════════════════════════════════

    /**
     * ⚠️ 501 codes is refused WITH A MESSAGE, not discovered by timeout.
     *
     * The discriminator is that NO JOB IS DISPATCHED — an implementation that
     * dispatches and then fails mid-render passes any assertion about the
     * response alone.
     */
    #[Test]
    public function selecting_more_than_the_cap_is_refused_and_dispatches_nothing(): void
    {
        Queue::fake();
        $admin = $this->adminWith(['view_qr_inventory']);

        $ids = range(1, GenerateQrExportJob::MAX_CODES + 1);
        SmartQrCode::factory()->count(3)->create();
        $real = SmartQrCode::pluck('id')->all();
        // Pad with real ids so `exists` validation passes, then exceed the cap.
        $ids = array_merge($real, array_fill(0, GenerateQrExportJob::MAX_CODES, $real[0]));

        $this->actingAs($admin, 'admin')
            ->from(route('admin.qr.inventory.index'))
            ->post(route('admin.qr.inventory.export'), ['code_ids' => $ids])
            ->assertSessionHasErrors('code_ids');

        Queue::assertNothingPushed();
    }

    /** POSITIVE CONTROL: a selection within the cap dispatches. */
    #[Test]
    public function a_selection_within_the_cap_dispatches_the_job(): void
    {
        Queue::fake();
        $admin = $this->adminWith(['view_qr_inventory']);
        $codes = SmartQrCode::factory()->count(3)->create();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.qr.inventory.export'), ['code_ids' => $codes->pluck('id')->all()])
            ->assertSessionHasNoErrors();

        Queue::assertPushed(GenerateQrExportJob::class, fn ($job) => $job->format === 'svg'
            && count($job->codeIds) === 3);
    }

    // ══ ⚠️ THE FAILURE PATH ════════════════════════════════════════════════

    /**
     * ⚠️ A HALF-BUILT ARCHIVE IS DELETED, NOT LEFT.
     *
     * A truncated ZIP downloads happily and fails to open — worse than an
     * absent one, because the admin discovers it after sending it to a printer.
     * Slice 2 refused to leave orphaned codes for exactly this reason.
     *
     * The discriminator is NO FILE ON DISK, not that an exception was thrown.
     */
    #[Test]
    public function a_failed_export_leaves_no_partial_archive(): void
    {
        Storage::fake('local');
        // ⚠️ TWO codes, and the renderer succeeds ONCE before throwing.
        //
        // Measured: with a single code that fails immediately, the archive has
        // ZERO entries — and ZipArchive::close() DELETES an empty archive, so
        // nothing leaks and the test cannot fail. A genuinely HALF-BUILT archive
        // needs at least one successful entry, which is also the only case that
        // matters in production.
        $codes = SmartQrCode::factory()->count(2)->create();
        $tempBefore = $this->tempFileCount();

        $this->app->bind(SmartQrImageRenderer::class, function () {
            return new class extends SmartQrImageRenderer
            {
                private int $calls = 0;

                public function svg(string $url, string $serial): array
                {
                    if (++$this->calls > 1) {
                        throw new \RuntimeException('render exploded');
                    }

                    return parent::svg($url, $serial);
                }
            };
        });

        try {
            (new GenerateQrExportJob($codes->pluck('id')->all(), 'svg'))->handle(app(SmartQrImageRenderer::class));
            $this->fail('The export swallowed a render failure and reported success.');
        } catch (\RuntimeException) {
            // expected — the queue must see the job fail
        }

        $files = Storage::disk('local')->allFiles('smartqr-exports');

        $this->assertSame([], $files,
            'A partial archive survived a failed export at the DESTINATION path. A truncated ZIP '
            .'downloads and then fails to open, which an admin discovers after sending it to a '
            .'printer.');

        // ⚠️ AND THE TEMP FILE, WHICH IS WHERE THE REAL LEAK IS.
        //
        // Measured: removing the destination cleanup alone leaves this test
        // green, because the archive is built at a TEMP path and only moved to
        // the disk on success — so nothing partial ever reaches `$relative` and
        // that branch is defensive rather than load-bearing.
        //
        // What a failed export genuinely leaves behind is the half-written temp
        // file. On a busy queue that accumulates silently in the system temp
        // directory until the volume fills, which is the failure this assertion
        // actually detects.
        $this->assertSame($tempBefore, $this->tempFileCount(),
            'A half-written temp archive was left in the system temp directory. Every failed '
            .'export would leak one, silently, until the volume filled.');
    }

    /** How many of our temp archives exist right now. */
    private function tempFileCount(): int
    {
        return count(glob(sys_get_temp_dir().'/smartqr*') ?: []);
    }

    /** POSITIVE CONTROL: a successful export DOES write an archive. */
    #[Test]
    public function a_successful_export_writes_a_readable_archive(): void
    {
        Storage::fake('local');
        $codes = SmartQrCode::factory()->count(2)->create();

        (new GenerateQrExportJob($codes->pluck('id')->all(), 'svg'))
            ->handle(app(SmartQrImageRenderer::class));

        $files = Storage::disk('local')->allFiles('smartqr-exports');

        $this->assertCount(1, $files, 'The export produced no archive, so the failure test above '
            .'proves nothing.');
        $this->assertStringEndsWith('.zip', $files[0]);
        $this->assertGreaterThan(0, Storage::disk('local')->size($files[0]));
    }

    // ══ §11's two holes ════════════════════════════════════════════════════

    #[Test]
    public function a_customer_cannot_preview_or_download_a_code_they_do_not_hold(): void
    {
        ['user' => $user] = $this->createWorkspaceContext();
        $user->forceFill(['client_role' => User::CLIENT_ROLE_ADMINISTRATOR])->save();

        $this->attachPlanToClient($user->client, Plan::factory()->create([
            'limits' => ['smart_qr_max_assigned' => 50],
        ]));

        $foreign = SmartQrCode::factory()->create();

        $this->actingAs($user)->get(route('client.smartqr.codes.preview', $foreign->serial_number))->assertNotFound();
        $this->actingAs($user)->get(route('client.smartqr.codes.download', $foreign->serial_number))->assertNotFound();
    }

    // ══ ⚠️ SLICE 8b — the export size note, and the inversion it exists for ══

    /**
     * ⚠️ THE SIZE FIGURES ARE MEASURED, AND SLICE 8's PLAN HAD THEM BACKWARDS.
     *
     * The plan justified SVG-by-default with "a few hundred KB against 50–150 MB
     * of PNG". Measured in a real ZipArchive, WITH a logo configured — the
     * intended production state — SVG is roughly 3x LARGER, because endroid's
     * SvgWriter embeds the logo as a base64 data URI in every single file while
     * the PNG writer rasterises it into one already-compressed bitmap.
     *
     * This test pins the INVERSION, in both directions, because the admin export
     * modal shows these numbers to somebody deciding what to wait for. A note
     * that names the wrong format as the expensive one is worse than no note:
     * it is a confident wrong answer.
     *
     * (SVG remains the default. The reason that survives measurement is that it
     * is vector and prints crisply at any size — the size claim was a wrong
     * number that happened to point at the right default.)
     */
    #[Test]
    public function the_export_size_estimate_inverts_when_a_logo_is_configured(): void
    {
        SystemSetting::set('app_logo_path', '');

        $plain = $this->renderer()->zippedBytesPerCode();

        $this->assertLessThan($plain['png'], $plain['svg'],
            'With no logo, SVG should be the smaller format.');

        Storage::fake('public');
        $image = imagecreatetruecolor(64, 64);
        ob_start();
        imagepng($image);
        Storage::disk('public')->put('logo.png', (string) ob_get_clean());
        SystemSetting::set('app_logo_path', 'logo.png');
        SystemSetting::set('app_logo_disk', 'public');

        $withLogo = $this->renderer()->zippedBytesPerCode();

        // ⚠️ The whole point. If a refactor ever makes these two branches
        // return the same array, this is the assertion that notices.
        $this->assertGreaterThan($withLogo['png'], $withLogo['svg'],
            'With a logo configured, SVG is the LARGER format — endroid embeds the logo as '
            .'base64 in every file. A UI note claiming otherwise sends an admin to the wrong '
            .'format believing it is the cheap one.');

        $this->assertGreaterThan($plain['svg'] * 10, $withLogo['svg'],
            'Positive control: the logo branch is genuinely a different, much larger figure.');
    }

    /**
     * The measured figures reach the page that shows them.
     *
     * ⚠️ Computed server-side deliberately: the two branches differ by ~100x and
     * React cannot know whether a platform logo is configured.
     */
    #[Test]
    public function the_inventory_page_carries_the_measured_export_estimate_and_the_cap(): void
    {
        $admin = $this->adminWith(['view_qr_inventory']);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.qr.inventory.index'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->has('exportBytesPerCode.svg')
                ->has('exportBytesPerCode.png')
                ->where('exportMaxCodes', GenerateQrExportJob::MAX_CODES)
            );
    }
}
