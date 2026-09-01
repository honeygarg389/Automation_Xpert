# Smart QR bundled assets

## OpenSans-SemiBold.ttf

Rendered onto the **serial-number label of every printed QR code** by
`SmartQrImageRenderer` (PNG path). Loaded through endroid's generic
`Endroid\QrCode\Label\Font\Font` with an explicit path, because endroid bundles
only Open Sans **Regular** (`usWeightClass` 400) and GD does not synthesise
weight — so without this file the PNG label cannot be bold at all, while the
SVG/PDF label is.

| | |
|---|---|
| Family | Open Sans SemiBold |
| `usWeightClass` | 600 (verified from the OS/2 table, not the filename) |
| Variable font | No — a static instance. A variable `OpenSans[wdth,wght].ttf` would render at its default 400 instance under FreeType and silently defeat the point. |
| Source | Google Fonts (`fonts.gstatic.com`), Open Sans v44 |
| Licence | SIL Open Font License 1.1 — see `OpenSans-LICENSE.txt` |
| Coverage | Latin subset, 231 codepoints |

⚠️ **The Latin subset is sufficient only because the serial alphabet is
constrained.** `StoreQrBatchRequest` pins the batch prefix to `[A-Z0-9]+` and the
sequence is `%06d`, so every character that can reach this font is covered
(verified against the cmap). If the prefix rule is ever widened — lowercase,
accents, any non-ASCII — characters outside the subset render as `.notdef`
boxes on the PNG while the SVG, which uses a full system face, looks correct.
Widen the rule and this file must be replaced with a fuller cut.

⚠️ **Licence obligations under OFL 1.1:** the font may be bundled and
redistributed, including commercially, provided `OpenSans-LICENSE.txt` travels
with it and the font is not sold on its own. Do not rename the file to something
containing a Reserved Font Name if it is ever modified.

This directory mirrors endroid's own `assets/` convention. There was no existing
third-party-notice file in this repository when it was added; if one is
introduced later, this entry should move there.
