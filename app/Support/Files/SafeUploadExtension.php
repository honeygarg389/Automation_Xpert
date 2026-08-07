<?php

namespace App\Support\Files;

use Illuminate\Http\UploadedFile;

/**
 * SEC-004. Derives the extension a file is STORED under from its actual
 * content, never from the name the client supplied.
 *
 * The defect this exists to prevent: `mimes:` validates the *sniffed*
 * extension, but every branding/media upload built the stored filename from
 * `getClientOriginalExtension()` — an unvalidated attacker string. A file whose
 * bytes begin `GIF89a` and continue `<script>…</script>` passes `mimes:…,gif,…`
 * and, named `payload.html`, was stored as `<uuid>.html`. Uploads live on the
 * `public` disk and are served by the WEB SERVER, not by PHP, so the response
 * gets its Content-Type from that extension and never passes through
 * SecureHeaders — no CSP, no nosniff. Same-origin stored XSS.
 *
 * The safe sites in this codebase already do the right thing by using
 * `hashName()`, which builds its name from `guessExtension()`. This is that
 * same rule, extracted so the sites that compose their own filenames can share
 * it instead of each inventing one.
 *
 * `nosniff` is not a defence here and neither is the CSP: the Content-Type
 * genuinely IS text/html, and `script-src` is `'self' 'unsafe-inline'`, which a
 * same-origin inline script satisfies. The extension is the only control.
 */
final class SafeUploadExtension
{
    /**
     * Extensions the browser will execute or parse as a document, whatever the
     * bytes turn out to be.
     *
     * This is the fail-closed layer. Validation allow-lists should already keep
     * these out, but an allow-list is only correct for as long as nobody edits
     * it — and this path has been wrong once already.
     */
    private const DENIED = ['html', 'htm', 'xhtml', 'shtml', 'svg', 'xml'];

    /** Anything sniffed as denied or unrecognised is stored inert. */
    private const FALLBACK = 'bin';

    public static function for(UploadedFile $file): string
    {
        // guessExtension() reads the file's contents. getClientOriginalExtension()
        // reads the upload's filename. Only the first is evidence.
        $extension = strtolower((string) $file->guessExtension());

        if ($extension === '' || in_array($extension, self::DENIED, true)) {
            return self::FALLBACK;
        }

        return $extension;
    }
}
