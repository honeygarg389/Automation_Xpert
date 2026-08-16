{{--
    §14's printable artwork for one code.

    ⚠️ The QR is an <img> with a data URI, NOT inline <svg>. Dompdf silently
    ignores inline SVG and produces a blank page — measured, and pinned by
    `the_module_matrix_survives_the_pdf_conversion`.

    ⚠️ Nothing here carries a customer name, a WhatsApp number or any permanent
    business detail (§14). The serial is already inside the SVG; the caption
    below is a print aid for whoever handles the sheet.
--}}
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><style>
    @page { margin: 12mm; }
    body { margin: 0; font-family: sans-serif; text-align: center; }
    .qr { width: 60mm; }
    .caption { margin-top: 4mm; font-size: 10pt; color: #000; }
</style></head>
<body>
    <img class="qr" src="{{ $svgDataUri }}" alt="">
    <div class="caption">S.No: {{ $serial }}</div>
</body>
</html>
