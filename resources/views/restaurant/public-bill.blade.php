<!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
<title>{{ $bill['brand']['name'] }} · Digital bill</title>
<style>
:root{--brand:{{ $bill['brand']['primary_color'] }};--ink:#172033;--muted:#667085;--line:#e6e9ef;--surface:#f4f6fa}*{box-sizing:border-box}body{margin:0;background:var(--surface);color:var(--ink);font:15px/1.45 system-ui,-apple-system,"Segoe UI",sans-serif}main{max-width:520px;min-height:100vh;margin:auto;background:#fff}.cover{height:200px;background:linear-gradient(135deg,var(--brand),#101828);overflow:hidden}.cover img{width:100%;height:100%;object-fit:cover}.identity{padding:0 22px 22px;border-bottom:1px solid var(--line)}.logo{display:grid;place-items:center;width:72px;height:72px;margin-top:-36px;overflow:hidden;border:4px solid #fff;border-radius:20px;background:#fff;color:var(--brand);box-shadow:0 4px 14px rgb(16 24 40 / .15);font-size:25px;font-weight:800}.logo img{width:100%;height:100%;object-fit:cover}h1{margin:12px 0 2px;font-size:25px}h2{margin:0;font-size:16px}.muted{color:var(--muted)}.outlet{margin-top:10px;font-size:14px}.wrap{padding:20px 22px}.card{border:1px solid var(--line);border-radius:17px;overflow:hidden}.summary,.row{display:grid;grid-template-columns:1fr auto;gap:12px;padding:15px 17px;border-bottom:1px solid var(--line)}.summary{background:#fcfcfd}.summary strong{display:block;font-size:18px}.row:last-child{border-bottom:0}.item-name{font-weight:700}.item-meta{font-size:13px;color:var(--muted)}.amount{text-align:right;white-space:nowrap}.totals{margin-top:14px;border-top:1px solid var(--line)}.total{font-size:20px;font-weight:800;color:var(--brand)}.thanks,.profile{margin-top:16px;padding:17px;border:1px solid var(--line);border-radius:15px;background:#fff}.thanks{background:#f8fafc}.profile p{margin:4px 0 14px}.button{width:100%;padding:12px;border:0;border-radius:10px;background:var(--brand);color:#fff;font:inherit;font-weight:700;cursor:pointer}.flash{margin:0 22px;padding:11px 14px;border-radius:10px;background:#ecfdf3;color:#067647;font-weight:600}.social{display:flex;justify-content:center;gap:10px;margin-top:19px}.social a{display:grid;place-items:center;width:40px;height:40px;border:1px solid var(--line);border-radius:50%;color:var(--brand);text-decoration:none;font-weight:700}.sr{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0)}footer{padding:4px 22px 28px;color:var(--muted);font-size:12px;text-align:center}dialog{width:min(100% - 24px,480px);border:0;border-radius:20px;padding:0;box-shadow:0 18px 60px rgb(16 24 40 / .3)}dialog::backdrop{background:rgb(16 24 40 / .55)}.dialog-body{padding:22px}.dialog-head{display:flex;justify-content:space-between;gap:18px}.close{border:0;background:none;font-size:28px;cursor:pointer}.field{display:block;margin:12px 0}.field span{display:block;margin-bottom:5px;font-weight:600}.field input,.field select{width:100%;padding:12px;border:1px solid #cdd3df;border-radius:10px;font:inherit}.field input[readonly]{background:#f5f6f8}.errors{padding:10px;border-radius:10px;background:#fff1f2;color:#b42318}@media(max-width:480px){.identity,.wrap{padding-left:16px;padding-right:16px}.flash{margin-left:16px;margin-right:16px}}
</style></head><body><main>
<div class="cover">
@if ($bill['brand']['cover_url'])
<img src="{{ $bill['brand']['cover_url'] }}" alt="">
@endif
</div>
<header class="identity"><div class="logo">
@if ($bill['brand']['logo_url'])
<img src="{{ $bill['brand']['logo_url'] }}" alt="{{ $bill['brand']['name'] }} logo">
@else
{{ mb_strtoupper(mb_substr($bill['brand']['name'], 0, 1)) }}
@endif
</div><h1>{{ $bill['brand']['name'] }}</h1>
@if ($bill['outlet']['name'])<p class="muted">{{ $bill['outlet']['name'] }}</p>@endif
@if ($bill['outlet']['address'] || $bill['outlet']['phone'])<p class="outlet muted">{{ $bill['outlet']['address'] }} @if ($bill['outlet']['phone']) · {{ $bill['outlet']['phone'] }} @endif</p>@endif
</header>
@if (session('profile_updated'))<p class="flash" role="status">Your contact details have been updated.</p>@endif
<div class="wrap"><section class="card" aria-labelledby="bill-title"><div class="summary"><div><p class="muted">Digital bill</p><strong id="bill-title">Order {{ $bill['bill']['number'] }}</strong></div><div class="amount muted">{{ $bill['bill']['placed_at'] }}<br>{{ $bill['bill']['order_type'] }}<br>{{ $bill['bill']['status'] }}</div></div>
@forelse ($bill['bill']['items'] as $item)
<div class="row"><div><div class="item-name">{{ $item['name'] }}</div><div class="item-meta">{{ $item['quantity'] }} × {{ $item['unit_price'] }}</div></div><div class="amount">{{ $item['total'] }}</div></div>
@empty
<div class="row"><span class="muted">Item details are unavailable for this bill.</span></div>
@endforelse
</section>
<section class="totals" aria-label="Bill totals">
@foreach ($bill['bill']['taxes'] as $tax)<div class="row"><span>{{ $tax['name'] }}</span><span class="amount">{{ $tax['amount'] }}</span></div>@endforeach
@foreach ($bill['bill']['discounts'] as $discount)<div class="row"><span>{{ $discount['name'] }}</span><span class="amount">−{{ $discount['amount'] }}</span></div>@endforeach
@if ($bill['bill']['tax_total'] !== null && ! $bill['bill']['taxes'])<div class="row"><span>Tax</span><span class="amount">{{ $bill['bill']['tax_total'] }}</span></div>@endif
@if ($bill['bill']['discount_total'] !== null && ! $bill['bill']['discounts'])<div class="row"><span>Discount</span><span class="amount">−{{ $bill['bill']['discount_total'] }}</span></div>@endif
@if ($bill['bill']['total'] !== null)<div class="row total"><span>Total {{ $bill['bill']['currency_code'] }}</span><span class="amount">{{ $bill['bill']['currency_symbol'] }}{{ $bill['bill']['total'] }}</span></div>@endif
</section>
@if ($bill['thank_you_note'])<section class="thanks"><h2>Thank you</h2><p class="muted">{{ $bill['thank_you_note'] }}</p></section>@endif
@if ($bill['customer_profile'])<section class="profile"><h2>Complete your profile</h2><p class="muted">Keep your contact details up to date for a better restaurant experience.</p><button class="button" type="button" data-profile-open>Update your profile</button></section>@endif
@if ($bill['outlet']['website'] || $bill['social_links'])<nav class="social" aria-label="Restaurant links">
@if ($bill['outlet']['website'])<a href="{{ $bill['outlet']['website'] }}" target="_blank" rel="noopener noreferrer" aria-label="Website">◉<span class="sr">Website</span></a>@endif
@foreach ($bill['social_links'] as $name => $url)
@php($icon = ['facebook' => 'f', 'instagram' => '◎', 'youtube' => '▶', 'google' => 'G', 'x' => '𝕏'][$name] ?? '↗')
<a href="{{ $url }}" target="_blank" rel="noopener noreferrer" aria-label="{{ ucfirst($name) }}">{{ $icon }}<span class="sr">{{ ucfirst($name) }}</span></a>
@endforeach
</nav>@endif
</div><footer>{{ $bill['disclaimer'] }}</footer>
@if ($bill['customer_profile'])
<dialog id="profile-dialog" aria-labelledby="profile-title"><div class="dialog-body"><div class="dialog-head"><div><h2 id="profile-title">Update your profile</h2><p class="muted">Your details are saved to this restaurant only.</p></div><button class="close" type="button" data-profile-close aria-label="Close">×</button></div>
@if ($errors->any())<div class="errors">Please correct the highlighted details.</div>@endif
<form method="post" action="{{ route('public.restaurant.bills.profile.update', ['token' => request()->route('token')]) }}">@csrf
<label class="field"><span>First name</span><input name="first_name" required value="{{ old('first_name', $bill['customer_profile']['first_name']) }}"></label><label class="field"><span>Last name</span><input name="last_name" value="{{ old('last_name', $bill['customer_profile']['last_name']) }}"></label><label class="field"><span>Email</span><input type="email" name="email" value="{{ old('email', $bill['customer_profile']['email']) }}"></label><label class="field"><span>Date of birth</span><input type="date" name="birthday" value="{{ old('birthday', $bill['customer_profile']['birthday']) }}"></label><label class="field"><span>Pincode</span><input name="postal_code" value="{{ old('postal_code', $bill['customer_profile']['postal_code']) }}"></label><label class="field"><span>Mobile number</span><input readonly value="{{ $bill['customer_profile']['phone'] }}"></label><label class="field"><span>Gender</span><select name="gender"><option value="">Prefer not to say</option>@foreach (\App\Modules\Shared\Models\Contact::GENDER_LABELS as $value => $label)<option value="{{ $value }}" @selected(old('gender', $bill['customer_profile']['gender']) === $value)>{{ $label }}</option>@endforeach</select></label><button class="button" type="submit">Save details</button></form></div></dialog>
<script>const dialog=document.getElementById('profile-dialog');document.querySelector('[data-profile-open]')?.addEventListener('click',()=>dialog.showModal());document.querySelector('[data-profile-close]')?.addEventListener('click',()=>dialog.close());@if ($errors->any())dialog.showModal();@endif</script>
@endif
</main></body></html>
