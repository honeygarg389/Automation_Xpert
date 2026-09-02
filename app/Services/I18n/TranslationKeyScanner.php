<?php

namespace App\Services\I18n;

use Illuminate\Support\Str;

class TranslationKeyScanner
{
    /**
     * Discover translation keys from JS/TS/JSX/TSX and Blade files.
     * Returns list of keys (e.g. "common.save", "nav.dashboard") with optional group.
     */
    public function discoverKeys(): array
    {
        $keys = [];
        $base = base_path();

        $jsDir = $base.'/resources/js';
        $jsExt = ['jsx', 'tsx', 'js', 'ts'];
        $this->globRecursive($jsDir, $jsExt, function ($path) use (&$keys) {
            $content = @file_get_contents($path);
            if ($content) {
                $this->extractKeysFromJs($content, $keys);
            }
        });

        $viewsDir = $base.'/resources/views';
        $this->globRecursive($viewsDir, ['php'], function ($path) use (&$keys) {
            if (! str_ends_with($path, '.blade.php')) {
                return;
            }
            $content = @file_get_contents($path);
            if ($content) {
                $this->extractKeysFromBlade($content, $keys);
            }
        });

        return array_values(array_unique($keys));
    }

    private function globRecursive(string $dir, array $extensions, callable $callback): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if (! $file->isFile()) {
                continue;
            }
            $path = $file->getPathname();
            $ext = strtolower($file->getExtension());
            if (in_array($ext, $extensions, true)) {
                $callback($path);
            }
        }
    }

    /**
     * Extract keys from JS: t('key'), t("key"), i18n.t('key'), __('key').
     */
    private function extractKeysFromJs(string $content, array &$keys): void
    {
        // t('...') or t("...") or t(`...`)
        if (preg_match_all('/\bt\s*\(\s*[\'"`]([^\'"`]+)[\'"`]\s*\)/u', $content, $m)) {
            foreach ($m[1] as $key) {
                $keys[] = trim($key);
            }
        }
        // i18n.t('...')
        if (preg_match_all('/i18n\.t\s*\(\s*[\'"`]([^\'"`]+)[\'"`]\s*\)/u', $content, $m)) {
            foreach ($m[1] as $key) {
                $keys[] = trim($key);
            }
        }
        // ⚠️ THE CONTEXT-FREE PATTERN — the root cause of BUG-037, kept because
        // deleting it loses real keys, and narrowed instead.
        //
        // It exists for keys referenced INDIRECTLY, which the anchored patterns
        // above cannot see — e.g. `{ labelKey: 'email_editor.tab_templates' }`
        // in a table that is later fed to t(). Measured: 602 such keys have no
        // literal t('…') call anywhere, so removing this pattern would silently
        // stop translating them.
        //
        // ⚠️ Its failure is that it cannot tell a translation key from a ROUTE
        // NAME — both are dotted lowercase strings. `route('client.inbox.setup')`
        // was harvested as a key, humanised to "Setup" by keyToDefaultEnglish(),
        // and then destroyed the real `client.inbox.setup.*` subtree.
        //
        // ⚠️ ROUTING CALLS ARE STRIPPED BEFORE MATCHING, not filtered after.
        // Filtering the RESULT by "is this string also a route name" is wrong:
        // measured, 154 strings in this codebase are legitimately BOTH a route
        // name and a translation key, so a result filter deletes real keys.
        // Removing the routing CALL removes only that occurrence, leaving any
        // genuine t('client.pricing') elsewhere still discoverable.
        //
        // ⚠️ THE WRAPPER LIST IS NOT A COMPLETE DEFENCE and is not treated as
        // one. `safeRoute()` already defeats a `route(`-only filter, and the
        // next wrapper will defeat this list too. That is precisely why
        // I18nFileService::unflatten() refuses to overwrite populated nodes:
        // this narrows the input, that one makes the damage impossible.
        $scannable = preg_replace(
            '/\b(?:safeRoute|route)\s*\(\s*[\'"`][^\'"`]*[\'"`]/u',
            'route(',
            $content
        ) ?? $content;

        if (preg_match_all('/[\'"`]([a-zA-Z0-9_]+(?:\.[a-zA-Z0-9_]+)+)[\'"`]\s*(?:\)|,|\s)/u', $scannable, $m)) {
            foreach ($m[1] as $key) {
                $key = trim($key);
                if (! Str::contains($key, '.') || strlen($key) <= 2 || strlen($key) >= 120) {
                    continue;
                }
                if ($this->looksLikeFilename($key)) {
                    continue;
                }
                $keys[] = $key;
            }
        }
    }

    /**
     * ⚠️ `brand.png`, `Codes.jsx`, `x.zip` were all being harvested as
     * translation keys and written into the shipped dictionary as "Png",
     * "Jsx", "Zip". A filename is not a key in any locale.
     */
    private function looksLikeFilename(string $key): bool
    {
        static $ext = [
            'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'ico', 'bmp',
            'zip', 'pdf', 'csv', 'xlsx', 'json', 'xml', 'txt', 'md',
            'js', 'jsx', 'ts', 'tsx', 'css', 'scss', 'html', 'php',
            'mp4', 'mp3', 'wav', 'woff', 'woff2', 'ttf', 'otf',
        ];

        return in_array(strtolower(substr($key, (int) strrpos($key, '.') + 1)), $ext, true);
    }

    private function extractKeysFromBlade(string $content, array &$keys): void
    {
        // @lang('...') or __('...')
        if (preg_match_all('/@lang\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/u', $content, $m)) {
            foreach ($m[1] as $key) {
                $keys[] = trim($key);
            }
        }
        if (preg_match_all('/__\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/u', $content, $m)) {
            foreach ($m[1] as $key) {
                $keys[] = trim($key);
            }
        }
    }

    /**
     * Convert a key like "common.save" to group "common" and key "save"; "dashboard" to group "app", key "dashboard".
     */
    public function keyToGroupAndKey(string $flatKey): array
    {
        if (Str::contains($flatKey, '.')) {
            $parts = explode('.', $flatKey);
            $key = array_pop($parts);
            $group = implode('.', $parts) ?: 'app';

            return [$group, $key];
        }

        return ['app', $flatKey];
    }

    /**
     * Generate a human-readable English label from key (e.g. "sidebar.dashboard" -> "Dashboard").
     */
    public function keyToDefaultEnglish(string $flatKey): string
    {
        [$group, $key] = $this->keyToGroupAndKey($flatKey);
        $keyPart = $key;
        $keyPart = str_replace(['_', '-'], ' ', $keyPart);

        return Str::title($keyPart);
    }
}
