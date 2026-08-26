<?php

namespace Tests\Feature\SmartQr;

use App\Models\AdminUser;
use App\Models\Permission;
use App\Models\Plan;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use App\Modules\SmartQr\Jobs\GenerateQrExportJob;
use App\Modules\SmartQr\Models\SmartQrAssignment;
use App\Modules\SmartQr\Models\SmartQrBatch;
use App\Modules\SmartQr\Models\SmartQrCode;
use App\Modules\SmartQr\Services\SmartQrImageRenderer;
use App\Support\Files\SafeUploadExtension;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ZipArchive;

/**
 * Per-batch QR logo — the BACKEND half.
 *
 * ─── ⚠️ WHAT THIS FILE EXISTS TO PIN ────────────────────────────────────────
 *
 *   `file` not `image`   the validation rule means what it says — gif/webp/bmp
 *                        are REFUSED, which ['image','mimes:png,jpg,jpeg']
 *                        would silently accept
 *   the stored path      extension from sniffed content, name a UUID, disk
 *                        `local` (private) and RECORDED alongside the path
 *   the render           the batch's logo reaches the artwork, and a batch
 *                        without one stays PLAIN
 *   the ZIP              a mixed export brands per BATCH, not per install
 *
 * ⚠️ EVERY ASSERTION HERE IS ON THE STORED ROW OR THE RENDERED BYTES, never on
 * "the request succeeded" or "the job was dispatched with the right argument".
 * `$fillable` discards unlisted keys silently, and a logo argument can be
 * accepted all the way to the builder and still be dropped — both have happened
 * in this module.
 */
class SmartQrBatchLogoTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): AdminUser
    {
        $admin = AdminUser::factory()->create();
        $role = Role::firstOrCreate(
            ['key' => 'qr-logo-test'],
            ['name' => 'QR Logo Test Role', 'description' => 'test']
        );

        foreach (['manage_qr_batches', 'view_qr_inventory'] as $key) {
            $perm = Permission::firstOrCreate(
                ['key' => $key],
                ['name' => ucwords(str_replace('_', ' ', $key)), 'category' => 'QR Management']
            );
            $role->permissions()->syncWithoutDetaching([$perm->id]);
        }

        $admin->roles()->syncWithoutDetaching([$role->id]);

        return $admin->fresh();
    }

    /** @return array<string, mixed> */
    private function batchPayload(array $overrides = []): array
    {
        return array_merge([
            'batch_name' => 'Business Kit',
            'batch_number' => 'AX-BK-'.Str::upper(Str::random(6)),
            'prefix' => 'AX'.Str::upper(Str::random(3)),
            'quantity' => 2,
            'serial_start' => 1,
        ], $overrides);
    }

    /**
     * A real file on disk carrying CHOSEN bytes.
     *
     * ⚠️ `UploadedFile::fake()->image()` cannot be used for the refusal tests:
     * it produces a real image of the type named, so it proves the rule accepts
     * what it should and nothing about what it must refuse. These need bytes
     * that disagree with the filename.
     */
    private function fileWith(string $bytes, string $clientFilename): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'qrlogo_');
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $clientFilename, null, null, true);
    }

    private function pngBytes(): string
    {
        $image = imagecreatetruecolor(64, 64);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    private function gifBytes(): string
    {
        $image = imagecreatetruecolor(64, 64);
        ob_start();
        imagegif($image);

        return (string) ob_get_clean();
    }

    /** The SEC-004 payload: valid GIF to a sniffer, HTML document to a browser. */
    private function polyglot(string $clientFilename): UploadedFile
    {
        return $this->fileWith(
            "GIF89a\x01\x00\x01\x00\x00\xff\x00,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x00;"
            .'<script>alert(document.domain)</script>',
            $clientFilename
        );
    }

    // ══ 1. SCHEMA ══════════════════════════════════════════════════════════

    #[Test]
    public function the_batch_table_carries_a_nullable_logo_path_and_disk(): void
    {
        $this->assertTrue(Schema::hasColumn('smart_qr_batches', 'logo_path'));
        $this->assertTrue(Schema::hasColumn('smart_qr_batches', 'logo_disk'));

        // ⚠️ NULLABLE is the assertion that matters — every existing batch
        // predates this column, and a NOT NULL addition would have failed the
        // migration on any install with rows.
        $batch = SmartQrBatch::factory()->create();
        $this->assertNull($batch->fresh()->logo_path);
        $this->assertNull($batch->fresh()->logo_disk);
    }

    // ══ 2. UPLOAD + STORAGE ════════════════════════════════════════════════

    /**
     * ⚠️ ASSERTS THE STORED ROW, not the response.
     *
     * `logo_path`/`logo_disk` reach the batch only if they are in `$fillable`.
     * Mass assignment discards unlisted keys with no error and no exception —
     * this module has already shipped that exact bug once (slice 4's scan
     * columns), so a 302 proves nothing here.
     */
    #[Test]
    public function an_uploaded_logo_is_stored_privately_and_recorded_on_the_batch(): void
    {
        Storage::fake('local');
        Queue::fake();

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.qr.batches.store'), $this->batchPayload([
                'logo' => $this->fileWith($this->pngBytes(), 'brand.png'),
            ]))
            ->assertRedirect();

        $batch = SmartQrBatch::latest('id')->firstOrFail();

        $this->assertNotNull($batch->logo_path, 'The upload never reached the batch row.');

        // ⚠️ THE PRIVATE DISK, NAMED. Batch logos are read server-side by GD and
        // never fetched by a browser, so `public` would publish an asset for no
        // reason. `local` is the same disk the export ZIPs use.
        $this->assertSame('local', $batch->logo_disk);
        $this->assertTrue(Storage::disk('local')->exists($batch->logo_path),
            'The row names a file that is not on the disk it names.');

        // The name is a UUID under branding/, so an upload can neither choose
        // where it lands nor overwrite anything.
        $this->assertStringStartsWith('branding/qr-logo-', $batch->logo_path);
        $this->assertStringEndsWith('.png', $batch->logo_path);
    }

    /** A batch created without a logo keeps both columns null. */
    #[Test]
    public function creating_a_batch_without_a_logo_leaves_the_columns_null(): void
    {
        Queue::fake();

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.qr.batches.store'), $this->batchPayload())
            ->assertRedirect();

        $batch = SmartQrBatch::latest('id')->firstOrFail();

        $this->assertNull($batch->logo_path);
        $this->assertNull($batch->logo_disk);
    }

    // ══ 3. THE VALIDATION RULE — `file`, NOT `image` ═══════════════════════

    /**
     * ═══ ⚠️ A MEASURED CORRECTION: `file` AND `image` ARE NOT DIFFERENT HERE ═
     *
     * This test was written to prove that `file` refuses a GIF where `image`
     * would accept it. **Mutation-checked, and that is false.** Swapping `file`
     * for `image` in StoreQrBatchRequest leaves every assertion in this file
     * green. Measured directly, all four combinations:
     *
     *     file  + mimes:png,jpg,jpeg       gif reject   png ACCEPT
     *     image + mimes:png,jpg,jpeg       gif reject   png ACCEPT
     *     file  + mimes:png,jpg,jpeg,gif   gif ACCEPT   png ACCEPT
     *     image + mimes:png,jpg,jpeg,gif   gif ACCEPT   png ACCEPT
     *
     * The two rules are applied CONJUNCTIVELY — `image` does not override
     * `mimes:`, it intersects with it, and `mimes:` is the narrower of the two.
     * So `image` can only ever REMOVE from the allow-list, never add.
     *
     * ⚠️ That also re-reads the SystemSettingsController comment correctly. Its
     * `svg` token was dead because `image` EXCLUDES svg — narrowing — not
     * because `image` widened anything. "Overrode" is loose phrasing for a
     * one-directional narrowing.
     *
     * ⚠️ `file` IS STILL THE RIGHT SPELLING, and the reason is the history
     * rather than today's behaviour: `image` carries its own list that moves
     * between framework versions, so a rule written as `image` + a WIDE `mimes:`
     * has its true allow-set decided by the framework. `file` makes `mimes:` the
     * whole answer, permanently. What must not be claimed is that swapping them
     * today changes anything — it does not, and a test asserting otherwise would
     * be a confident wrong answer.
     *
     * What this test now honestly pins is the allow-list itself: png/jpg/jpeg,
     * and a GIF is refused.
     */
    #[Test]
    public function a_gif_is_refused_by_the_allow_list(): void
    {
        Queue::fake();

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.qr.batches.store'), $this->batchPayload([
                'logo' => $this->fileWith($this->gifBytes(), 'brand.gif'),
            ]))
            ->assertSessionHasErrors('logo');

        $this->assertSame(0, SmartQrBatch::count(),
            'The batch was created despite the rejected logo.');
    }

    /** POSITIVE CONTROL: the same request with a PNG is accepted. */
    #[Test]
    public function a_png_is_accepted_so_the_refusals_are_not_refusing_everything(): void
    {
        Storage::fake('local');
        Queue::fake();

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.qr.batches.store'), $this->batchPayload([
                'logo' => $this->fileWith($this->pngBytes(), 'brand.png'),
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, SmartQrBatch::count());
    }

    /**
     * SEC-004. A GIF/HTML polyglot named `.html` is refused on CONTENT.
     *
     * ⚠️ Two layers, and this asserts the first: `mimes:png,jpg,jpeg` sniffs
     * the bytes, sees `gif`, and rejects. SafeUploadExtension is the fail-closed
     * second layer beneath it — asserted separately below, because a rule that
     * is correct today is only correct for as long as nobody edits it.
     */
    #[Test]
    public function a_polyglot_named_html_is_refused_on_its_content(): void
    {
        Queue::fake();

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.qr.batches.store'), $this->batchPayload([
                'logo' => $this->polyglot('payload.html'),
            ]))
            ->assertSessionHasErrors('logo');

        $this->assertSame(0, SmartQrBatch::count());
    }

    /** An SVG carrying script is refused at the rule. */
    #[Test]
    public function an_svg_upload_is_refused(): void
    {
        Queue::fake();

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.qr.batches.store'), $this->batchPayload([
                'logo' => $this->fileWith(
                    '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)">'
                    .'<script>alert(document.domain)</script></svg>',
                    'brand.svg'
                ),
            ]))
            ->assertSessionHasErrors('logo');
    }

    /**
     * ⚠️ THE STORED EXTENSION COMES FROM THE CONTENT, NOT THE FILENAME.
     *
     * A valid PNG named `brand.txt` is ACCEPTED — correctly, the bytes are a
     * PNG and `mimes:` judges bytes — and must land on disk as `.png`. The rule
     * has nothing to say about the stored name; the only thing between a
     * client-supplied extension and the filesystem is SafeUploadExtension.
     * This is the assertion that would have caught the original SEC-004 defect,
     * which was never about the validation rule at all.
     *
     * ⚠️ `.txt` AND NOT `.php`, AND THAT IS A MEASURED CORRECTION. This test
     * was first written with `brand.php` and FAILED: Laravel's `mimes`
     * implementation calls `shouldBlockPhpUpload()`, which refuses an upload on
     * its CLIENT extension (php, php3…, phtml, htm, html) before it ever looks
     * at the content, unless the rule names those extensions explicitly. So a
     * PNG named `.php` never reaches storage at all — pinned separately below.
     * `.txt` is the disguise that actually exercises this path.
     */
    #[Test]
    public function the_stored_extension_is_sniffed_and_never_the_client_filename(): void
    {
        Storage::fake('local');
        Queue::fake();

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.qr.batches.store'), $this->batchPayload([
                'logo' => $this->fileWith($this->pngBytes(), 'brand.txt'),
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $batch = SmartQrBatch::latest('id')->firstOrFail();

        $this->assertStringEndsNotWith('.txt', $batch->logo_path,
            'The file was stored under the client-supplied extension. That is the exact shape of '
            .'the original SEC-004 defect.');
        $this->assertStringEndsWith('.png', $batch->logo_path);
    }

    /**
     * ⚠️ A FRAMEWORK BEHAVIOUR THIS RULE DEPENDS ON, PINNED SO A CHANGE IN IT
     * BREAKS A TEST RATHER THAN THE APPLICATION.
     *
     * `mimes:` blocks php/phtml/htm/html by CLIENT extension before sniffing.
     * That is a real second layer here — but it is the framework's, not ours,
     * and the platform-logo rule already demonstrated what happens when a
     * control is actually framework-version luck nobody wrote down.
     * SafeUploadExtension remains the layer we own.
     */
    #[Test]
    public function the_framework_blocks_a_php_named_upload_before_sniffing_it(): void
    {
        Queue::fake();

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.qr.batches.store'), $this->batchPayload([
                'logo' => $this->fileWith($this->pngBytes(), 'brand.php'),
            ]))
            ->assertSessionHasErrors('logo');

        $this->assertSame(0, SmartQrBatch::count());
    }

    /**
     * POSITIVE CONTROL for the layer we own: SafeUploadExtension stores an
     * unrecognised or denied payload inert, whatever it was called.
     */
    #[Test]
    public function safe_upload_extension_is_what_names_the_stored_file(): void
    {
        $this->assertSame('png', SafeUploadExtension::for(
            $this->fileWith($this->pngBytes(), 'brand.txt')
        ));

        $this->assertSame('bin', SafeUploadExtension::for(
            $this->fileWith('<svg xmlns="http://www.w3.org/2000/svg"/>', 'brand.png')
        ));
    }

    // ══ 4. THE RENDER ══════════════════════════════════════════════════════

    /**
     * ⚠️ END TO END, AND ON THE ZIP ENTRY'S BYTES.
     *
     * The export is where a per-batch logo is actually spent — 500 stickers at
     * a time — and it is the one path that renders codes from MORE THAN ONE
     * BATCH in a single job. A logo resolved once and reused for every code
     * would brand the whole archive with whichever batch happened to be first.
     *
     * The discriminator is that the two entries DIFFER. An implementation that
     * ignores logos entirely, or applies one logo to everything, produces
     * identical bytes and fails here.
     */
    #[Test]
    public function a_mixed_export_brands_each_code_from_its_own_batch(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('branding/qr-logo-a.png', $this->pngBytes());

        $branded = SmartQrBatch::factory()->create([
            'logo_path' => 'branding/qr-logo-a.png',
            'logo_disk' => 'local',
        ]);
        $plain = SmartQrBatch::factory()->create(['logo_path' => null]);

        $brandedCode = SmartQrCode::factory()->create(['batch_id' => $branded->id]);
        $plainCode = SmartQrCode::factory()->create(['batch_id' => $plain->id]);

        (new GenerateQrExportJob([$brandedCode->id, $plainCode->id], 'svg'))
            ->handle(app(SmartQrImageRenderer::class));

        $files = Storage::disk('local')->allFiles('smartqr-exports');
        $this->assertCount(1, $files, 'No archive was produced, so nothing below is tested.');

        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('local')->path($files[0])) === true);

        $brandedSvg = $zip->getFromName($brandedCode->serial_number.'.svg');
        $plainSvg = $zip->getFromName($plainCode->serial_number.'.svg');
        $zip->close();

        $this->assertIsString($brandedSvg, 'The branded batch\'s code is missing from the archive.');
        $this->assertIsString($plainSvg, 'The plain batch\'s code is missing from the archive.');

        // ⚠️ base64 is how endroid embeds a logo into an SVG. Its presence in
        // one entry and absence in the other is the logo itself, not a proxy.
        $this->assertStringContainsString('base64', $brandedSvg,
            'The branded batch produced an SVG with no embedded image — its logo was dropped '
            .'between the column and the canvas, and 500 stickers would print unbranded.');
        $this->assertStringNotContainsString('base64', $plainSvg,
            'A batch with no logo received one anyway. In a mixed export the logo is being '
            .'resolved once and reused, so whichever batch sorts first brands the whole archive.');
    }

    /**
     * ═══ ⚠️ THE OWNER'S RULING, ASSERTED AT THE RESOLVER ══════════════════
     *
     * There is NO global fallback. A batch with no logo of its own renders
     * plain even when the installation has a perfectly good platform logo
     * configured and resolvable.
     *
     * ⚠️ THIS TEST EXISTS BECAUSE THE OBVIOUS ONE DID NOT COVER IT. The
     * companion assertion in SmartQrImageExportTest compares RENDERED BYTES
     * with and without a configured platform logo — but it renders through
     * `svg($url, $serial)` with no batch at all, so it never enters
     * batchLogoPath(). Mutation-checked: adding
     * `?: SystemSetting::get('app_logo_path')` to the resolver left that test,
     * and every other test in both files, GREEN.
     *
     * The fallback risk lives in the RESOLVER, so the assertion has to be on
     * the resolver. Both halves are kept: one proves the renderer invents no
     * logo, this proves the resolver inherits none.
     *
     * ⚠️ What is at stake is not tidiness. BUG-038 records that the only logo
     * assets in this repository are WhatsMine-branded, inherited from the
     * original import. A silent inheritance is discovered as a box of printed
     * stickers carrying another product's brand.
     */
    #[Test]
    public function a_configured_platform_logo_is_not_inherited_by_a_logoless_batch(): void
    {
        Storage::fake('public');
        Storage::fake('local');

        // A REAL, resolvable platform logo — the positive control for the
        // control. An absent file would make this pass for the wrong reason.
        Storage::disk('public')->put('logo.png', $this->pngBytes());
        SystemSetting::set('app_logo_path', 'logo.png');
        SystemSetting::set('app_logo_disk', 'public');
        $this->assertTrue(Storage::disk('public')->exists('logo.png'));

        $batch = SmartQrBatch::factory()->create(['logo_path' => null, 'logo_disk' => null]);

        $this->assertNull(app(SmartQrImageRenderer::class)->batchLogoPath($batch),
            'A batch with no logo of its own inherited the platform logo. There must be NO '
            .'fallback: the installation brand is not the print run\'s brand, and the only '
            .'assets in this repo belong to a different product (BUG-038).');

        // ⚠️ AND THROUGH THE JOB, on the archive's actual bytes — the resolver
        // returning null proves nothing about what the export does with it.
        $code = SmartQrCode::factory()->create(['batch_id' => $batch->id]);

        (new GenerateQrExportJob([$code->id], 'svg'))->handle(app(SmartQrImageRenderer::class));

        $files = Storage::disk('local')->allFiles('smartqr-exports');
        $this->assertCount(1, $files);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('local')->path($files[0])) === true);
        $svg = $zip->getFromName($code->serial_number.'.svg');
        $zip->close();

        $this->assertIsString($svg);
        $this->assertStringNotContainsString('base64', $svg,
            'The exported artwork carries an embedded image for a batch that has no logo. The '
            .'platform logo is reaching print through the export path.');
    }

    /**
     * ═══ ⚠️ THE CUSTOMER-FACING RENDER, THROUGH THE CONTROLLER ════════════
     *
     * Preview and download go through SmartQrCodeController, which resolves the
     * logo itself — a completely separate call site from the export job. This
     * asserts the RESPONSE BODY, not the renderer.
     *
     * ⚠️ ADDED BECAUSE A MUTATION PROVED IT MISSING. Replacing the controller's
     * `batchLogoPath($code->batch)` with `null` left all 42 tests across three
     * files green: every existing logo assertion called the renderer directly,
     * so the controller's own wiring was untested. A customer would have
     * downloaded unbranded artwork for a branded run and nothing would have
     * noticed.
     */
    #[Test]
    public function the_customer_preview_and_download_carry_the_batch_logo(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('branding/qr-logo-c.png', $this->pngBytes());

        ['workspace' => $workspace, 'user' => $user] = $this->createWorkspaceContext();
        $user->forceFill(['client_role' => User::CLIENT_ROLE_ADMINISTRATOR])->save();

        $this->attachPlanToClient($user->client, Plan::factory()->create([
            'limits' => ['smart_qr_max_assigned' => 50],
        ]));

        $batch = SmartQrBatch::factory()->create([
            'logo_path' => 'branding/qr-logo-c.png',
            'logo_disk' => 'local',
        ]);
        $code = SmartQrCode::factory()->create(['batch_id' => $batch->id]);

        SmartQrAssignment::factory()->create([
            'smart_qr_code_id' => $code->id,
            'workspace_id' => $workspace->id,
        ]);

        $preview = $this->actingAs($user)
            ->get(route('client.smartqr.codes.preview', $code->serial_number))
            ->assertOk();

        $this->assertStringContainsString('base64', $preview->getContent(),
            'The customer preview rendered without the batch logo. The proof on screen is not the '
            .'artwork in the box.');

        $download = $this->actingAs($user)
            ->get(route('client.smartqr.codes.download', $code->serial_number).'?format=svg')
            ->assertOk();

        $this->assertStringContainsString('base64', $download->getContent(),
            'The customer download rendered without the batch logo.');
    }

    /**
     * POSITIVE CONTROL: the same two routes on a LOGO-LESS batch return plain
     * artwork — so the assertions above are not passing on some unrelated
     * base64 that every response happens to contain.
     */
    #[Test]
    public function the_customer_preview_of_a_logoless_batch_is_plain(): void
    {
        Storage::fake('local');

        ['workspace' => $workspace, 'user' => $user] = $this->createWorkspaceContext();
        $user->forceFill(['client_role' => User::CLIENT_ROLE_ADMINISTRATOR])->save();

        $this->attachPlanToClient($user->client, Plan::factory()->create([
            'limits' => ['smart_qr_max_assigned' => 50],
        ]));

        $code = SmartQrCode::factory()->create([
            'batch_id' => SmartQrBatch::factory()->create(['logo_path' => null])->id,
        ]);

        SmartQrAssignment::factory()->create([
            'smart_qr_code_id' => $code->id,
            'workspace_id' => $workspace->id,
        ]);

        $preview = $this->actingAs($user)
            ->get(route('client.smartqr.codes.preview', $code->serial_number))
            ->assertOk();

        $this->assertStringNotContainsString('base64', $preview->getContent());
    }

    /**
     * The customer's download is the same artwork as the export.
     *
     * ⚠️ Preview and download render on demand through a different controller
     * from the ZIP. Threading the logo into one and not the other gives an admin
     * an unbranded proof of a branded run — approved on screen, wrong in the box.
     */
    #[Test]
    public function an_admin_reachable_render_of_a_branded_batch_carries_the_logo(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('branding/qr-logo-b.png', $this->pngBytes());

        $batch = SmartQrBatch::factory()->create([
            'logo_path' => 'branding/qr-logo-b.png',
            'logo_disk' => 'local',
        ]);
        $code = SmartQrCode::factory()->create(['batch_id' => $batch->id]);

        $renderer = app(SmartQrImageRenderer::class);
        $logo = $renderer->batchLogoPath($code->fresh()->batch);

        $this->assertNotNull($logo, 'The batch logo did not resolve from the code\'s batch.');

        $svg = $renderer->svg('https://x.test/q/abc', $code->serial_number, $logo)['data'];
        $this->assertStringContainsString('base64', $svg);
    }
}
