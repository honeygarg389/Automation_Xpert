<?php

namespace App\Modules\Flows\Services;

/**
 * Section B (imported-flow round-trip fix) — the single place every Meta
 * Flow JSON identifier (component `name`, and any other Meta-constrained
 * alphabetic+underscore identifier) is generated or sanitized. Before this,
 * identifier generation was scattered: WhatsappFlowMetaSyncService's
 * placeholderScreens() hardcoded the literal string "field_1", and
 * Builder.jsx's blankField() generated "field_{step}_{order}" — both
 * numeric-suffixed, which Meta rejects for this class of identifier.
 *
 * The core rule this class exists to enforce: a GENERATED disambiguation
 * suffix is always alphabetic (a, b, ..., z, aa, ab, ...), never numeric —
 * "field_1" is exactly the shape that must never come out of this class.
 * An identifier that arrives ALREADY VALID (e.g. imported straight from a
 * real Meta component's own `name`) is returned completely unchanged; this
 * class never renames something that was already fine.
 */
class MetaFlowIdentifier
{
    /**
     * Meta's constraint for this class of identifier: starts with a letter,
     * followed by any run of letters, digits, or underscores. Note digits
     * ARE allowed in the middle/end of an otherwise-valid identifier (e.g.
     * an imported "field2" stays "field2" — see normalize(), which treats
     * this differently from a trailing "_<digits>" suffix specifically).
     */
    public static function isValidName(string $value): bool
    {
        return $value !== '' && preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $value) === 1;
    }

    /**
     * True for the exact anti-pattern this class exists to eliminate: a
     * trailing underscore followed by digits only ("field_1", "screen_2",
     * "customer_name_1"). This is narrower than general character-class
     * validity — "email2" (digits with no preceding underscore) does NOT
     * match this and is left alone; only the "word_NUMBER" shape a bare
     * numeric disambiguation suffix produces is treated as needing
     * alphabetic normalization, even when it otherwise satisfies
     * isValidName().
     */
    public static function hasNumericSuffix(string $value): bool
    {
        return preg_match('/_[0-9]+$/', $value) === 1;
    }

    /**
     * Deterministic, purely-alphabetic disambiguation sequence with no
     * digits at any position: 0 -> a, 1 -> b, ..., 25 -> z, 26 -> aa, 27 ->
     * ab, .... This is the direct replacement for every place that used to
     * append a raw integer (```_1```, ```_2```, ...).
     */
    public static function alphabeticSuffix(int $index): string
    {
        $index = max(0, $index);
        $letters = '';
        $n = $index + 1; // 1-based internally so 0 maps to 'a', not the empty string.
        while ($n > 0) {
            $n--;
            $letters = chr(97 + ($n % 26)).$letters;
            $n = intdiv($n, 26);
        }

        return $letters;
    }

    /**
     * Normalizes an externally-authored candidate identifier (e.g. a name
     * read from imported Meta Flow JSON): kept completely unchanged when
     * already valid AND not already claimed; otherwise sanitized
     * deterministically (invalid characters collapsed to underscores, a
     * non-letter start prefixed with $fallbackBase) and, if that sanitized
     * form collides with something already claimed, disambiguated with an
     * alphabetic suffix so two different invalid originals (e.g.
     * "customer-name" and "customer_name", which sanitize to the same
     * string) never collapse onto the same final identifier.
     *
     * @param  array<string,true>  $used  Identifiers already claimed in this compile/decompile pass — mutated to record the value this call returns.
     */
    public static function normalize(string $raw, array &$used, string $fallbackBase = 'field'): string
    {
        $trimmed = trim($raw);
        $base = (self::isValidName($trimmed) && ! self::hasNumericSuffix($trimmed))
            ? $trimmed
            : self::sanitize($trimmed, $fallbackBase);

        if (! isset($used[$base])) {
            $used[$base] = true;

            return $base;
        }

        return self::disambiguate($base, $used);
    }

    /**
     * Generates a brand-new identifier with no externally-authored candidate
     * at all — the direct replacement for the old `"field_1"` / `"field_{$step}_{$order}"`
     * literal-generation call sites. Returns $base unsuffixed when it is not
     * yet claimed (so the very first generated field is plain "field", not
     * "field_a") and only adds an alphabetic suffix from the second onward.
     *
     * @param  array<string,true>  $used
     */
    public static function nextUnique(array &$used, string $base = 'field'): string
    {
        if (! isset($used[$base])) {
            $used[$base] = true;

            return $base;
        }

        return self::disambiguate($base, $used);
    }

    /**
     * Meta's constraint for a SCREEN id is STRICTER than a component name's:
     * confirmed via Meta's own real validation response for this compiler's
     * default "SCREEN_STEP_1_1"-shaped ids — "Property 'id' should only
     * consist of alphabets and underscores" (path `screens[N].id`). No
     * digits are permitted ANYWHERE, not even embedded ones — narrower than
     * isValidName()'s `[A-Za-z][A-Za-z0-9_]*`, which is why screen ids need
     * their own validity check rather than reusing that one.
     */
    public static function isValidScreenId(string $value): bool
    {
        return $value !== '' && preg_match('/^[A-Za-z][A-Za-z_]*$/', $value) === 1;
    }

    /**
     * Normalizes a candidate screen id: preserved completely unchanged when
     * already valid (letters and underscores only); otherwise every
     * non-alphabetic character — digits included, unlike normalize()'s
     * field-name sanitize() — collapses to an underscore. A collision
     * disambiguates with the exact same alphabetic suffix scheme
     * (a, b, ..., aa, ab, ...) used everywhere else in this class — already
     * digit-free, so it needs no special handling under this stricter rule.
     *
     * Always returns an UPPERCASE result: this compiler's own established
     * screen-id convention (`SCREEN_CONTACT_US`), not a Meta requirement —
     * kept consistent with the existing prefix shape rather than
     * introducing a second casing convention for screens specifically.
     *
     * @param  array<string,true>  $used  Screen ids already claimed in this compile() call — mutated to record the value this call returns.
     */
    public static function normalizeScreenId(string $raw, array &$used, string $fallbackBase = 'SCREEN'): string
    {
        $trimmed = strtoupper(trim($raw));
        $fallbackBase = strtoupper($fallbackBase);
        $base = self::isValidScreenId($trimmed) ? $trimmed : self::sanitizeScreenId($trimmed, $fallbackBase);

        if (! isset($used[$base])) {
            $used[$base] = true;

            return $base;
        }

        return strtoupper(self::disambiguate($base, $used));
    }

    /** @param array<string,true> $used */
    private static function disambiguate(string $base, array &$used): string
    {
        $index = 0;
        do {
            $attempt = $base.'_'.self::alphabeticSuffix($index);
            $index++;
        } while (isset($used[$attempt]));

        $used[$attempt] = true;

        return $attempt;
    }

    /**
     * Collapses invalid characters to underscores, prefixes a fallback base
     * when there is no letter to start from, then strips ANY trailing
     * "_<digits>" run(s) — the exact numeric-suffix shape hasNumericSuffix()
     * flags — down to the bare base. The stripped numeric value itself is
     * deliberately discarded rather than remapped 1:1 to an alphabetic
     * position: the bare base is handed to normalize()'s own collision
     * tracking, which applies a fresh alphabetic disambiguator ONLY if the
     * base turns out to actually collide with something else already
     * claimed — so a lone "field_1" becomes plain "field" (no suffix at
     * all), while "customer-name-1" landing on an already-claimed
     * "customer_name" becomes "customer_name_a" via the normal collision
     * path below, matching the task's own worked example.
     */
    private static function sanitize(string $raw, string $fallbackBase): string
    {
        $collapsed = trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', $raw), '_');
        if ($collapsed === '' || ! preg_match('/^[A-Za-z]/', $collapsed)) {
            $collapsed = $collapsed === '' ? $fallbackBase : $fallbackBase.'_'.$collapsed;
        }

        $stripped = (string) preg_replace('/(?:_[0-9]+)+$/', '', $collapsed);

        return $stripped !== '' ? $stripped : $fallbackBase;
    }

    /**
     * Screen-id sanitize: collapses every run of non-alphabetic characters
     * — digits included — to a single underscore, trims stray leading/
     * trailing underscores, and falls back to $fallbackBase if nothing
     * alphabetic survives. $raw and $fallbackBase both arrive already
     * uppercased by normalizeScreenId().
     */
    private static function sanitizeScreenId(string $raw, string $fallbackBase): string
    {
        $collapsed = trim((string) preg_replace('/[^A-Za-z]+/', '_', $raw), '_');

        return $collapsed !== '' && preg_match('/^[A-Za-z]/', $collapsed) === 1 ? $collapsed : $fallbackBase;
    }
}
