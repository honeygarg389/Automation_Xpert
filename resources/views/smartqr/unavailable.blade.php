{{--
    Themed page for every non-redirect scan outcome (§8).

    ⚠️ Reuses errors.layout so this matches the existing 404/500 pages' shell —
    no second visual system for one module. `showBranding` is false here: this
    page has no "Go Home"/"Go Back" for a stranger with no account on the site,
    and no logo/link back into the app for the same reason. The 404/500/419/503
    pages are unaffected — they still get both, via the layout's own default.

    ⚠️ `code` is the string "QR", not an HTTP status. Every "exists" outcome
    returns 200 and differs only in copy; putting a status number here would
    reintroduce the machine-readable distinction the 200 exists to remove.
--}}
@include('errors.layout', [
    'code'         => $code,
    'title'        => $title,
    'message'      => $message,
    'showBranding' => false,
])
