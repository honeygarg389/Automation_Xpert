<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
    <title>{{ $bill['brand']['name'] }} · Digital bill</title>
    <style>
        :root { --brand: {{ $bill['brand']['primary_color'] }}; --ink: #172033; --muted: #667085; --line: #e8ebf0; --paper: #fff; --surface: #f7f8fa; }
        * { box-sizing: border-box; } body { margin: 0; background: var(--surface); color: var(--ink); font: 15px/1.5 ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        main { max-width: 680px; margin: 0 auto; min-height: 100vh; background: var(--paper); }
        .cover { min-height: 128px; background: linear-gradient(135deg, var(--brand), #101828); background-size: cover; background-position: center; }
        .identity { padding: 0 24px 20px; border-bottom: 1px solid var(--line); }
        .logo { width: 68px; height: 68px; margin-top: -34px; display: grid; place-items: center; overflow: hidden; border: 4px solid #fff; border-radius: 18px; background: #fff; color: var(--brand); box-shadow: 0 4px 12px rgb(16 24 40 / 12%); font-size: 22px; font-weight: 800; }
        .logo img { width: 100%; height: 100%; object-fit: cover; } h1 { margin: 12px 0 2px; font-size: 24px; line-height: 1.2; } h2 { margin: 0; font-size: 16px; } p { margin: 0; }
        .muted { color: var(--muted); } .wrap { padding: 24px; } .card { border: 1px solid var(--line); border-radius: 16px; overflow: hidden; background: #fff; }
        .summary { display: flex; justify-content: space-between; gap: 12px; padding: 18px; background: #fcfcfd; border-bottom: 1px solid var(--line); } .summary strong { display: block; font-size: 17px; }
        .row { display: grid; grid-template-columns: 1fr auto; gap: 12px; padding: 15px 18px; border-bottom: 1px solid var(--line); } .row:last-child { border: 0; }
        .item-name { font-weight: 600; }.item-meta { color: var(--muted); font-size: 13px; }.amount { font-variant-numeric: tabular-nums; text-align: right; white-space: nowrap; }
        .totals { margin-top: 16px; border-top: 1px solid var(--line); }.total { font-size: 20px; font-weight: 800; color: var(--brand); }
        .thanks { margin-top: 18px; padding: 16px; border-radius: 12px; background: #f8fafc; }.outlet { margin-top: 10px; font-size: 14px; }.outlet a, .social a { color: var(--brand); text-decoration: none; }
        .social { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 18px; }.social a { border: 1px solid var(--line); border-radius: 999px; padding: 6px 10px; font-size: 13px; text-transform: capitalize; }
        footer { padding: 16px 24px 28px; color: var(--muted); font-size: 12px; text-align: center; }
        @media (max-width: 480px) { .identity, .wrap { padding-left: 16px; padding-right: 16px; } .summary, .row { padding-left: 14px; padding-right: 14px; } }
    </style>
</head>
<body>
<main>
    @if($bill['brand']['cover_url']) <div class="cover" style="background-image: linear-gradient(135deg, rgb(16 24 40 / .35), rgb(16 24 40 / .65)), url('{{ $bill['brand']['cover_url'] }}')"></div> @else <div class="cover"></div> @endif
    <header class="identity">
        <div class="logo">@if($bill['brand']['logo_url'])<img src="{{ $bill['brand']['logo_url'] }}" alt="{{ $bill['brand']['name'] }} logo">@else{{ mb_strtoupper(mb_substr($bill['brand']['name'], 0, 1)) }}@endif</div>
        <h1>{{ $bill['brand']['name'] }}</h1>
        @if($bill['outlet']['name']) <p class="muted">{{ $bill['outlet']['name'] }}</p> @endif
        @if($bill['outlet']['address'] || $bill['outlet']['phone'] || $bill['outlet']['website'])
            <p class="outlet muted">
                {{ $bill['outlet']['address'] }}
                @if($bill['outlet']['phone']) · {{ $bill['outlet']['phone'] }} @endif
                @if($bill['outlet']['website']) · <a href="{{ $bill['outlet']['website'] }}" rel="noopener noreferrer" target="_blank">Website</a> @endif
            </p>
        @endif
    </header>
    <div class="wrap">
        <section class="card" aria-labelledby="bill-title">
            <div class="summary"><div><p class="muted">Digital bill</p><strong id="bill-title">Order {{ $bill['bill']['number'] }}</strong></div><div class="amount muted">@if($bill['bill']['placed_at']){{ $bill['bill']['placed_at'] }}@endif @if($bill['bill']['order_type'])<br>{{ $bill['bill']['order_type'] }}@endif @if($bill['bill']['status'])<br>{{ $bill['bill']['status'] }}@endif</div></div>
            @forelse($bill['bill']['items'] as $item)
                <div class="row"><div><div class="item-name">{{ $item['name'] }}</div>
                    @if($item['quantity'] !== null || $item['unit_price'] !== null)<div class="item-meta">
                        @if($item['quantity'] !== null){{ $item['quantity'] }} × @endif
                        @if($item['unit_price'] !== null){{ $item['unit_price'] }}@endif
                    </div>@endif
                </div><div class="amount">@if($item['total'] !== null){{ $item['total'] }}@endif</div></div>
            @empty
                <div class="row"><span class="muted">Item details are unavailable for this bill.</span></div>
            @endforelse
        </section>
        @if($bill['bill']['taxes'] || $bill['bill']['discounts'] || $bill['bill']['tax_total'] !== null || $bill['bill']['discount_total'] !== null || $bill['bill']['total'] !== null)
            <section class="totals" aria-label="Bill totals">
                @foreach($bill['bill']['taxes'] as $tax)<div class="row"><span>{{ $tax['name'] }}</span><span class="amount">{{ $tax['amount'] }}</span></div>@endforeach
                @foreach($bill['bill']['discounts'] as $discount)<div class="row"><span>{{ $discount['name'] }}</span><span class="amount">−{{ $discount['amount'] }}</span></div>@endforeach
                @if($bill['bill']['tax_total'] !== null && !$bill['bill']['taxes'])<div class="row"><span>Tax</span><span class="amount">{{ $bill['bill']['tax_total'] }}</span></div>@endif
                @if($bill['bill']['discount_total'] !== null && !$bill['bill']['discounts'])<div class="row"><span>Discount</span><span class="amount">−{{ $bill['bill']['discount_total'] }}</span></div>@endif
                @if($bill['bill']['total'] !== null)<div class="row total"><span>Total @if($bill['bill']['currency_code'])<small>{{ $bill['bill']['currency_code'] }}</small>@endif</span><span class="amount">{{ $bill['bill']['currency_symbol'] }}{{ $bill['bill']['total'] }}</span></div>@endif
            </section>
        @endif
        @if($bill['thank_you_note'])<section class="thanks"><h2>Thank you</h2><p class="muted">{{ $bill['thank_you_note'] }}</p></section>@endif
        @if($bill['social_links'])<nav class="social" aria-label="Restaurant social links">@foreach($bill['social_links'] as $name => $url)<a href="{{ $url }}" rel="noopener noreferrer" target="_blank">{{ $name }}</a>@endforeach</nav>@endif
    </div>
    <footer>{{ $bill['disclaimer'] }}</footer>
</main>
</body>
</html>
