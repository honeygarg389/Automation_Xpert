{{--
    Themed page for every non-redirect scan outcome (§8).

    ⚠️ Reuses errors.layout so this matches the existing 404/500 pages exactly —
    same branding, same shell, no second visual system for one module.

    ⚠️ `code` is the string "QR", not an HTTP status. Every "exists" outcome
    returns 200 and differs only in copy; putting a status number here would
    reintroduce the machine-readable distinction the 200 exists to remove.
--}}
@include('errors.layout', [
    'code'    => $code,
    'title'   => $title,
    'message' => $message,
])
