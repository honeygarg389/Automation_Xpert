<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>{{ $brand['name'] }} · Feedback</title><style>:root{--brand:{{ $brand['primary_color'] }};--ink:#172033;--muted:#667085;--line:#e6e9ef}*{box-sizing:border-box}body{margin:0;background:#f4f6fa;color:var(--ink);font:16px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif}main{max-width:520px;min-height:100vh;margin:auto;background:#fff}.cover{height:200px;background:linear-gradient(135deg,var(--brand),#101828);overflow:hidden}.cover img{width:100%;height:100%;object-fit:cover}.identity{padding:0 22px 22px;border-bottom:1px solid var(--line)}.logo{display:grid;place-items:center;width:72px;height:72px;overflow:hidden;margin-top:-36px;border:4px solid #fff;border-radius:20px;background:#fff;color:var(--brand);box-shadow:0 4px 14px rgb(16 24 40 / .15);font-size:25px;font-weight:800}.logo img{width:100%;height:100%;object-fit:cover}h1{margin:12px 0 4px;font-size:26px}.muted{color:var(--muted)}.content{padding:24px 22px}.card{padding:20px;border:1px solid var(--line);border-radius:17px}.stars{display:flex;justify-content:space-between;gap:8px;margin:18px 0}.stars label{display:grid;place-items:center;width:48px;height:48px;border:1px solid var(--line);border-radius:50%;font-weight:700;cursor:pointer}.stars input{position:absolute;opacity:0}.stars input:checked+span{display:grid;place-items:center;width:100%;height:100%;border-radius:50%;background:var(--brand);color:#fff}textarea{width:100%;min-height:100px;padding:12px;border:1px solid #cdd3df;border-radius:10px;font:inherit}.button{width:100%;margin-top:14px;padding:12px;border:0;border-radius:10px;background:var(--brand);color:#fff;font:inherit;font-weight:700}.links{display:flex;justify-content:center;gap:10px;margin-top:20px}.links a{display:grid;place-items:center;width:40px;height:40px;border:1px solid var(--line);border-radius:50%;color:var(--brand);text-decoration:none;font-weight:700}.sr{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0)}@media(max-width:480px){.identity,.content{padding-left:16px;padding-right:16px}}</style></head><body><main>
<div class="cover">
@if ($brand['cover_url'])
<img src="{{ $brand['cover_url'] }}" alt="">
@endif
</div>
<header class="identity"><div class="logo">
@if ($brand['logo_url'])
<img src="{{ $brand['logo_url'] }}" alt="{{ $brand['name'] }} logo">
@else
{{ mb_strtoupper(mb_substr($brand['name'], 0, 1)) }}
@endif
</div><h1>{{ $brand['name'] }}</h1><p class="muted">Your feedback is shared privately with the restaurant.</p></header>
<div class="content"><section class="card"><h2>How was your experience?</h2><form method="post" action="{{ route('public.restaurant.feedback.submit', ['token' => $feedback->public_token]) }}">@csrf<div class="stars" aria-label="Rating">
@for ($i = 1; $i <= 5; $i++)
<label><input type="radio" name="rating" value="{{ $i }}" required><span>{{ $i }}</span></label>
@endfor
</div><label for="comment">Tell us more <span class="muted">(optional)</span></label><textarea id="comment" name="comment" maxlength="2000"></textarea><button class="button" type="submit">Send feedback</button></form></section>
@if ($reviewUrl)<p class="muted" style="margin-top:16px;text-align:center">Happy with your visit? <a href="{{ $reviewUrl }}" target="_blank" rel="noopener noreferrer">Leave a public review</a></p>@endif
@if ($brand['website'] || $brand['social_links'])<nav class="links" aria-label="Restaurant links">
@if ($brand['website'])<a href="{{ $brand['website'] }}" target="_blank" rel="noopener noreferrer" aria-label="Website">◉<span class="sr">Website</span></a>@endif
@foreach ($brand['social_links'] as $name => $url)
@php($icon = ['facebook' => 'f', 'instagram' => '◎', 'youtube' => '▶', 'google' => 'G', 'x' => '𝕏'][$name] ?? '↗')
<a href="{{ $url }}" target="_blank" rel="noopener noreferrer" aria-label="{{ ucfirst($name) }}">{{ $icon }}<span class="sr">{{ ucfirst($name) }}</span></a>
@endforeach
</nav>@endif
</div></main></body></html>
