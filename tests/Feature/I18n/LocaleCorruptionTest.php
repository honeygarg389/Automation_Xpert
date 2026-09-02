<?php

namespace Tests\Feature\I18n;

use App\Services\I18n\I18nFileService;
use App\Services\I18n\TranslationKeyScanner;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BUG-037 — the key scanner harvested route names and unflatten() then
 * destroyed the real translations they collided with, on every fresh install.
 *
 * ⚠️ THESE TESTS NEVER RUN `db:seed`. That command is what triggers the bug;
 * running it to test the fix would corrupt the very files under test. The
 * seeder's behaviour is exercised through the two functions it calls.
 */
class LocaleCorruptionTest extends TestCase
{
    /** The four subtrees BUG-037 was measured destroying. */
    private const VICTIMS = [
        'client.inbox.setup',
        'client.profile.2fa',
        'client.profile.sessions',
        'client.segments.contacts',
    ];

    private string $scratchLocale = 'zztest';

    protected function tearDown(): void
    {
        $path = resource_path('js/locales/'.$this->scratchLocale.'.json');
        if (file_exists($path)) {
            unlink($path);
        }
        parent::tearDown();
    }

    // ══ unflatten() — the destruction, both directions ══════════════════════

    /**
     * ⚠️ CASE A. A humanised scalar arrives on a populated node. Before the
     * fix the whole subtree was replaced by one word.
     */
    #[Test]
    public function a_scalar_never_overwrites_a_populated_node(): void
    {
        $flat = [
            'client.profile.2fa.enable' => 'Enable',
            'client.profile.2fa.disable' => 'Disable',
            'client.profile.2fa' => '2Fa',      // the scanner's guess
        ];

        $out = I18nFileService::unflatten($flat, $conflicts);

        $this->assertIsArray($out['client']['profile']['2fa'],
            'The populated 2fa node was flattened into a string — the Case A destruction.');
        $this->assertSame('Enable', $out['client']['profile']['2fa']['enable']);
        $this->assertSame('Disable', $out['client']['profile']['2fa']['disable']);
        $this->assertSame(['client.profile.2fa'], $conflicts,
            'The refusal must be reported, not silent.');
    }

    /**
     * ⚠️ CASE B. A key needs an object where a leaf string already sits. Before
     * the fix the string was replaced by an array and the translation vanished.
     */
    #[Test]
    public function an_object_never_buries_an_existing_leaf_string(): void
    {
        $flat = [
            'admin.clients' => 'Clients',
            'admin.clients.index' => 'Index',   // route name harvested as a key
        ];

        $out = I18nFileService::unflatten($flat, $conflicts);

        $this->assertSame('Clients', $out['admin']['clients'],
            'The existing leaf was buried under an object — the Case B destruction.');
        $this->assertSame(['admin.clients.index'], $conflicts);
    }

    /**
     * ⚠️ A POSITIVE CONTROL. Refusing everything would pass both tests above,
     * so prove ordinary nesting still works and nothing is reported.
     */
    #[Test]
    public function non_colliding_keys_still_nest_normally(): void
    {
        $flat = [
            'smart_qr.lock_qr' => 'Lock QR Status',
            'smart_qr.reason_label' => 'Reason:',
            'common.save' => 'Save',
        ];

        $out = I18nFileService::unflatten($flat, $conflicts);

        $this->assertSame('Lock QR Status', $out['smart_qr']['lock_qr']);
        $this->assertSame('Reason:', $out['smart_qr']['reason_label']);
        $this->assertSame('Save', $out['common']['save']);
        $this->assertSame([], $conflicts);
    }

    /** A refusal must not leave a half-built branch behind. */
    #[Test]
    public function a_refused_key_leaves_no_partial_structure(): void
    {
        $out = I18nFileService::unflatten([
            'a.b' => 'kept',
            'a.b.c.d' => 'rejected',
        ], $conflicts);

        $this->assertSame('kept', $out['a']['b']);
        $this->assertSame(['a.b.c.d'], $conflicts);
        $this->assertArrayNotHasKey('c', (array) $out['a'],
            'The rejected key created its parent objects anyway.');
    }

    // ══ the scanner — false positives at the source ═════════════════════════

    /**
     * ⚠️ Real strings from this codebase, not invented ones:
     * `route('client.media.store')`, `safeRoute('client.profile.2fa')`.
     */
    #[Test]
    public function route_names_are_not_harvested_as_translation_keys(): void
    {
        $keys = array_flip(app(TranslationKeyScanner::class)->discoverKeys());

        foreach (['client.media.store', 'client.notifications.index', 'admin.logout',
            'client.profile.2fa', 'client.profile.sessions', 'client.segments.contacts'] as $routeName) {
            $this->assertArrayNotHasKey($routeName, $keys,
                "Route name `{$routeName}` was harvested as a translation key.");
        }
    }

    /** `brand.png` / `Codes.jsx` were being written into the dictionary as "Png" / "Jsx". */
    #[Test]
    public function filenames_are_not_harvested_as_translation_keys(): void
    {
        $keys = array_flip(app(TranslationKeyScanner::class)->discoverKeys());

        foreach (['brand.png', 'logo.png', 'Codes.jsx', 'x.zip'] as $filename) {
            $this->assertArrayNotHasKey($filename, $keys,
                "Filename `{$filename}` was harvested as a translation key.");
        }
    }

    /**
     * ⚠️ THE REGRESSION GUARD. Narrowing the scanner must not stop it finding
     * real keys — including ones referenced INDIRECTLY, with no literal t('…')
     * call anywhere (`{ labelKey: 'email_editor.tab_templates' }`). 602 keys in
     * this codebase are only reachable that way.
     */
    #[Test]
    public function legitimate_keys_are_still_discovered(): void
    {
        $keys = array_flip(app(TranslationKeyScanner::class)->discoverKeys());

        foreach (['email_editor.tab_templates', 'email_editor.block_text',
            'smart_qr.lock_qr', 'smart_qr.reason_label'] as $real) {
            $this->assertArrayHasKey($real, $keys,
                "Legitimate key `{$real}` is no longer discovered.");
        }
    }

    // ══ end to end — what a fresh install actually does ═════════════════════

    /**
     * ⚠️ THE FRESH-INSTALL SHAPE. seedCore() calls i18n:seed-defaults, which
     * ends at putFlatDictionary(). This drives that same write path with a
     * deliberately colliding key set and asserts the file on disk survives.
     */
    #[Test]
    public function a_colliding_seed_cannot_corrupt_the_written_file(): void
    {
        $svc = app(I18nFileService::class);

        $good = [
            'client.profile.2fa.enable' => 'Enable',
            'client.profile.2fa.disable' => 'Disable',
            'admin.clients' => 'Clients',
        ];
        $this->assertTrue($svc->putFlatDictionary($this->scratchLocale, $good));

        // Now a scan that harvested route names runs over the top.
        $poisoned = $good + [
            'client.profile.2fa' => '2Fa',
            'admin.clients.index' => 'Index',
        ];
        $this->assertTrue($svc->putFlatDictionary($this->scratchLocale, $poisoned),
            'The write must still SUCCEED — a fresh install cannot be made to fail by this.');

        $onDisk = json_decode(file_get_contents(
            resource_path('js/locales/'.$this->scratchLocale.'.json')
        ), true);

        $this->assertSame('Enable', $onDisk['client']['profile']['2fa']['enable']);
        $this->assertSame('Disable', $onDisk['client']['profile']['2fa']['disable']);
        $this->assertSame('Clients', $onDisk['admin']['clients']);
    }

    /**
     * ⚠️ THE FOUR HISTORICAL VICTIMS, ON A SYNTHETIC FIXTURE — deliberately NOT
     * the live dictionary.
     *
     * This test used to read en.json and poison whatever children it found
     * there. That made it silently self-defeating: once the junk keys were
     * deleted from en.json (2026-09-03) the four victims had no children left,
     * the loop body never ran, and PHPUnit reported it Risky — a test asserting
     * nothing while still counting as green. A test whose subject is "corrupt
     * data" must not depend on corrupt data still being present.
     *
     * The fixture below reproduces both collision directions in one pass:
     * Case A for each of the four victims (a scalar landing on a populated
     * node) and one Case B (an object burying a leaf string).
     */
    #[Test]
    public function the_four_known_collision_shapes_are_all_refused(): void
    {
        $flat = [];

        // Real children, one or two per victim, mirroring the shapes measured
        // when the destruction was first reproduced.
        foreach (self::VICTIMS as $i => $victim) {
            $flat[$victim.'.alpha'] = 'Alpha '.$i;
            $flat[$victim.'.beta'] = 'Beta '.$i;
        }

        // CASE A — the humanised scalar the scanner would have produced.
        foreach (self::VICTIMS as $victim) {
            $flat[$victim] = 'Guess';
        }

        // CASE B — a leaf string that a later key wants to bury under an object.
        $flat['admin.clients'] = 'Clients';
        $flat['admin.clients.index'] = 'Index';

        $out = I18nFileService::unflatten($flat, $conflicts);

        $checked = 0;
        foreach (self::VICTIMS as $victim) {
            foreach (['alpha', 'beta'] as $child) {
                $node = $out;
                foreach (explode('.', $victim.'.'.$child) as $part) {
                    $node = $node[$part] ?? null;
                }
                $this->assertIsString($node, "`{$victim}.{$child}` was destroyed by `{$victim}`.");
                $checked++;
            }

            $this->assertContains($victim, $conflicts,
                "The refusal of `{$victim}` was not reported.");
        }

        // ⚠️ Guards against this test quietly becoming a no-op again: if the
        // fixture ever stops producing children, this fails rather than passing
        // with nothing asserted.
        $this->assertSame(8, $checked, 'The fixture stopped exercising the victims.');

        $this->assertSame('Clients', $out['admin']['clients'],
            'Case B: the existing leaf was buried under an object.');
        $this->assertContains('admin.clients.index', $conflicts);
        $this->assertCount(count(self::VICTIMS) + 1, $conflicts,
            'Exactly the five colliding keys should have been refused.');
    }
}
