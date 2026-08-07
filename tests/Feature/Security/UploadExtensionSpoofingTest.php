<?php

namespace Tests\Feature\Security;

use App\Models\AdminUser;
use App\Models\Client;
use App\Models\Media;
use App\Models\Permission;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\Files\SafeUploadExtension;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * SEC-004. Two separate defects, recorded as one.
 *
 * RECORDED: "SVG accepted for logo/favicon upload — stored XSS."
 * ACTUAL:
 *   1. Only the FAVICON rule accepted SVG. The logo rule did not, because
 *      Laravel 12's `image` rule excludes svg unless passed `allow_svg` — so
 *      the `svg` token in its `mimes` list was dead. That is version-dependent
 *      luck, not a control, hence the pinning test below.
 *   2. The real hole was the stored FILENAME, not the SVG. `mimes:` validates
 *      the sniffed extension while the stored path took
 *      `getClientOriginalExtension()` — the attacker's string. A GIF-magic
 *      polyglot named `payload.html` passed validation and was stored as
 *      `.html`, served as text/html from the app's own origin. That path is
 *      reachable by ANY tenant user via POST /media, not just an admin.
 *
 * Uploads land on the `public` disk and are served by the web server, so
 * SecureHeaders never runs on them: no CSP, no nosniff. The stored extension is
 * the only control there is, which is why these assertions are on the stored
 * PATH and not merely on the upload succeeding.
 */
class UploadExtensionSpoofingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A file that is a valid GIF to any content sniffer and an HTML document to
     * a browser. This is the actual exploit payload, not an approximation:
     * `GIF89a` satisfies `mimes:…,gif,…` and the trailing script runs if the
     * file is ever served as text/html.
     */
    private function polyglot(string $clientFilename): UploadedFile
    {
        $bytes = "GIF89a\x01\x00\x01\x00\x00\xff\x00,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x00;"
            .'<script>alert(document.domain)</script>';

        return $this->fileWith($bytes, $clientFilename);
    }

    private function svgWithScript(string $clientFilename = 'icon.svg'): UploadedFile
    {
        $bytes = '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)">'
            .'<script>alert(document.domain)</script></svg>';

        return $this->fileWith($bytes, $clientFilename);
    }

    /** A real file on disk — UploadedFile::fake() cannot carry chosen bytes. */
    private function fileWith(string $bytes, string $clientFilename): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'sec004_');
        file_put_contents($path, $bytes);

        // $test = true, so the file is accepted without a real HTTP upload.
        return new UploadedFile($path, $clientFilename, null, null, true);
    }

    private function clientUser(): User
    {
        return User::factory()->create(['role' => 'client', 'email_verified_at' => now()]);
    }

    /**
     * An admin who can reach the per-client branding route.
     *
     * `createSuperAdmin()` grants `manage_clients` but the route requires
     * `update_clients`, and RequirePermission REDIRECTS for HTML rather than
     * returning 403 — so without this the branding tests would sail past
     * `assertRedirect()` while never reaching the controller at all. The
     * positive control is what exposed that.
     */
    private function brandingAdmin(): AdminUser
    {
        $admin = $this->createSuperAdmin();

        $permission = Permission::firstOrCreate(
            ['key' => 'update_clients'],
            ['name' => 'Update Clients', 'category' => 'Clients']
        );

        $admin->roles->first()->permissions()->syncWithoutDetaching([$permission->id]);

        return $admin->fresh();
    }

    // ── The one that matters: the tenant-reachable media endpoint ───────────

    #[Test]
    public function the_media_endpoint_does_not_store_a_polyglot_under_a_html_extension(): void
    {
        Storage::fake('public');
        $user = $this->clientUser();

        $this->actingAs($user)
            ->post(route('client.media.store'), ['file' => $this->polyglot('payload.html')])
            ->assertSuccessful();

        $media = Media::where('mediable_id', $user->id)->firstOrFail();

        // Asserting on the STORED PATH. "The upload succeeded" proves nothing —
        // it succeeded before the fix too, which was the whole problem.
        $this->assertStringEndsNotWith('.html', $media->path,
            'A GIF/HTML polyglot was stored as .html and will be served as text/html from this origin.');
        $this->assertStringEndsWith('.gif', $media->path,
            'The stored extension should come from the sniffed content (gif), not the filename.');
    }

    #[Test]
    public function the_media_endpoint_does_not_store_a_polyglot_under_an_svg_extension(): void
    {
        Storage::fake('public');
        $user = $this->clientUser();

        $this->actingAs($user)
            ->post(route('client.media.store'), ['file' => $this->polyglot('payload.svg')])
            ->assertSuccessful();

        $media = Media::where('mediable_id', $user->id)->firstOrFail();

        $this->assertStringEndsNotWith('.svg', $media->path);
        $this->assertStringEndsWith('.gif', $media->path);
    }

    /**
     * POSITIVE CONTROL. The media library must keep working — a fix that
     * quietly broke ordinary uploads would pass every assertion above.
     */
    #[Test]
    public function ordinary_media_uploads_still_work_and_keep_a_usable_extension(): void
    {
        Storage::fake('public');
        $user = $this->clientUser();

        $this->actingAs($user)
            ->post(route('client.media.store'), ['file' => UploadedFile::fake()->image('holiday.jpg', 40, 40)])
            ->assertSuccessful();

        $media = Media::where('mediable_id', $user->id)->firstOrFail();

        $this->assertStringEndsWith('.jpg', $media->path);
        Storage::disk('public')->assertExists($media->path);

        // The original name survives as a display label; only the stored path
        // is sanitised.
        $this->assertSame('holiday.jpg', $media->filename);
    }

    // ── Admin branding: favicon ────────────────────────────────────────────

    #[Test]
    public function the_favicon_upload_refuses_an_svg_carrying_script(): void
    {
        Storage::fake('public');
        $admin = $this->createSuperAdmin();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.settings.favicon.upload'), ['favicon' => $this->svgWithScript()],
                ['Accept' => 'application/json'])
            ->assertStatus(422);

        $this->assertNull(SystemSetting::get('app_favicon_path'),
            'A rejected SVG must not have been stored.');
    }

    #[Test]
    public function the_favicon_upload_stores_a_polyglot_under_its_sniffed_extension(): void
    {
        Storage::fake('public');
        $admin = $this->createSuperAdmin();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.settings.favicon.upload'), ['favicon' => $this->polyglot('favicon.html')])
            ->assertRedirect();

        $path = (string) SystemSetting::get('app_favicon_path');

        $this->assertStringEndsNotWith('.html', $path);
        $this->assertStringEndsWith('.gif', $path);
    }

    /** POSITIVE CONTROL. */
    #[Test]
    public function an_ordinary_png_favicon_still_uploads(): void
    {
        Storage::fake('public');
        $admin = $this->createSuperAdmin();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.settings.favicon.upload'), ['favicon' => UploadedFile::fake()->image('fav.png', 32, 32)])
            ->assertRedirect();

        $path = (string) SystemSetting::get('app_favicon_path');

        $this->assertStringEndsWith('.png', $path);
        Storage::disk('public')->assertExists($path);
    }

    // ── Admin branding: logo ───────────────────────────────────────────────

    /**
     * PINNING TEST. The logo rule refuses SVG today only because Laravel 12's
     * `image` rule dropped svg from its hardcoded list. If someone adds
     * `allow_svg`, or the framework changes back, this test fails instead of
     * the application silently becoming exploitable.
     */
    #[Test]
    public function the_logo_upload_refuses_an_svg(): void
    {
        Storage::fake('public');
        $admin = $this->createSuperAdmin();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.settings.logo.upload'), ['logo' => $this->svgWithScript('logo.svg')],
                ['Accept' => 'application/json'])
            ->assertStatus(422);

        $this->assertNull(SystemSetting::get('app_logo_path'));
    }

    #[Test]
    public function the_logo_upload_stores_a_polyglot_under_its_sniffed_extension(): void
    {
        Storage::fake('public');
        $admin = $this->createSuperAdmin();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.settings.logo.upload'), ['logo' => $this->polyglot('logo.html')])
            ->assertRedirect();

        $path = (string) SystemSetting::get('app_logo_path');

        $this->assertStringEndsNotWith('.html', $path);
        $this->assertStringEndsWith('.gif', $path);
    }

    /** POSITIVE CONTROL. */
    #[Test]
    public function an_ordinary_png_logo_still_uploads(): void
    {
        Storage::fake('public');
        $admin = $this->createSuperAdmin();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.settings.logo.upload'), ['logo' => UploadedFile::fake()->image('logo.png', 200, 60)])
            ->assertRedirect();

        $path = (string) SystemSetting::get('app_logo_path');

        $this->assertStringEndsWith('.png', $path);
        Storage::disk('public')->assertExists($path);
    }

    // ── Admin branding: per-client logo ────────────────────────────────────

    #[Test]
    public function the_client_branding_logo_stores_a_polyglot_under_its_sniffed_extension(): void
    {
        Storage::fake('public');
        $admin = $this->brandingAdmin();
        ['client' => $client] = $this->createWorkspaceContext();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.clients.branding', $client), ['logo' => $this->polyglot('brand.html')])
            ->assertRedirect();

        $path = (string) $client->fresh()->logo_path;

        $this->assertStringEndsNotWith('.html', $path);
        $this->assertStringEndsWith('.gif', $path);
    }

    /** POSITIVE CONTROL. */
    #[Test]
    public function an_ordinary_client_branding_logo_still_uploads(): void
    {
        Storage::fake('public');
        $admin = $this->brandingAdmin();
        ['client' => $client] = $this->createWorkspaceContext();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.clients.branding', $client), ['logo' => UploadedFile::fake()->image('brand.png', 120, 40)])
            ->assertRedirect();

        $path = (string) $client->fresh()->logo_path;

        $this->assertStringEndsWith('.png', $path);
        Storage::disk('public')->assertExists($path);
    }

    // ── The denylist, directly ─────────────────────────────────────────────

    /**
     * Layer 3. Nothing currently reaches SafeUploadExtension with SVG content —
     * every allow-list excludes it. This asserts the fail-closed behaviour
     * anyway, because allow-lists get edited and this path has been wrong once.
     */
    #[Test]
    public function content_that_sniffs_as_an_executable_document_is_stored_inert(): void
    {
        foreach (['icon.svg' => 'svg', 'page.html' => 'html'] as $name => $_) {
            $this->assertSame('bin', SafeUploadExtension::for($this->svgWithScript($name)),
                'SVG content must never be stored under an extension a browser will execute.');
        }

        $html = $this->fileWith('<html><body><script>alert(1)</script></body></html>', 'x.png');
        $this->assertSame('bin', SafeUploadExtension::for($html));
    }

    #[Test]
    public function the_extension_is_taken_from_content_not_from_the_filename(): void
    {
        $this->assertSame('gif', SafeUploadExtension::for($this->polyglot('anything.html')));
        $this->assertSame('gif', SafeUploadExtension::for($this->polyglot('anything.exe')));
    }
}
