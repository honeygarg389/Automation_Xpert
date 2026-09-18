<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $form['name'] }}</title>
    @if ($recaptcha['enabled'] && $recaptcha['configured'])
        <script src="https://www.google.com/recaptcha/api.js" async defer></script>
    @endif
    <style>
        :root { color-scheme: light; font-family: Inter, ui-sans-serif, system-ui, sans-serif; color: #171717; background: #f5f5f5; }
        body { margin: 0; padding: 2rem 1rem; }
        main { width: min(100%, 42rem); margin: 0 auto; border: 1px solid #e5e5e5; border-radius: .75rem; background: #fff; padding: clamp(1.25rem, 4vw, 2.5rem); box-shadow: 0 8px 24px rgb(0 0 0 / .06); }
        h1 { margin: 0; font-size: 1.5rem; } h2 { margin: 0 0 .4rem; font-size: 1.125rem; } p { line-height: 1.5; color: #525252; }
        .step[hidden] { display: none; } .field { margin-top: 1.25rem; } label, legend { display: block; font-size: .875rem; font-weight: 600; } fieldset { border: 0; padding: 0; margin: 0; }
        input:not([type=radio]):not([type=checkbox]), textarea, select { box-sizing: border-box; width: 100%; margin-top: .45rem; border: 1px solid #a3a3a3; border-radius: .5rem; padding: .65rem .75rem; font: inherit; }
        textarea { min-height: 7rem; resize: vertical; } .choice { display: flex; align-items: center; gap: .55rem; margin-top: .55rem; font-weight: 400; }
        .helper, .error { display: block; margin-top: .4rem; font-size: .8125rem; } .helper { color: #737373; } .error { color: #b91c1c; } .actions { display: flex; justify-content: space-between; gap: .75rem; margin-top: 2rem; }
        button { border: 0; border-radius: .5rem; padding: .7rem 1rem; background: #2563eb; color: #fff; font: inherit; font-weight: 600; cursor: pointer; } button.secondary { background: #e5e5e5; color: #262626; } button:disabled { cursor: not-allowed; opacity: .55; }
        .success { border: 1px solid #86efac; border-radius: .5rem; background: #f0fdf4; padding: 1rem; color: #166534; } .notice { color: #92400e; }
    </style>
</head>
<body>
<main>
    @if ($successMessage)
        <div class="success" role="status"><h1>{{ $form['name'] }}</h1><p>{{ $successMessage }}</p></div>
    @else
        <h1>{{ $form['name'] }}</h1>
        @if ($form['description']) <p>{{ $form['description'] }}</p> @endif
        @error('limit')<p class="notice" role="alert">{{ $message }}</p>@enderror
        <form method="POST" action="{{ route('public.flows.form.submit', $flow->public_slug) }}" novalidate>
            @csrf
            @foreach ($form['steps'] as $stepIndex => $step)
                <section class="step" data-step="{{ $stepIndex }}" {{ $stepIndex !== 0 ? 'hidden' : '' }}>
                    <h2>{{ $step['title'] }}</h2>
                    @foreach ($step['fields'] as $field)
                        @if ($field['type'] === 'heading')
                            <div class="field"><h2>{{ $field['label'] }}</h2>@if ($field['helper_text'])<p class="helper">{{ $field['helper_text'] }}</p>@endif</div>
                        @elseif ($field['type'] === 'textarea')
                            <div class="field"><label for="field-{{ $field['id'] }}">{{ $field['label'] }}</label><textarea id="field-{{ $field['id'] }}" name="{{ $field['name'] }}" @required($field['required'])>{{ old($field['name']) }}</textarea>@include('flows.partials.field-error', ['name' => $field['name'], 'helper' => $field['helper_text']])</div>
                        @elseif ($field['type'] === 'select')
                            <div class="field"><label for="field-{{ $field['id'] }}">{{ $field['label'] }}</label><select id="field-{{ $field['id'] }}" name="{{ $field['name'] }}" @required($field['required'])><option value="">Select an option</option>@foreach ($field['options'] as $option)<option value="{{ $option['id'] }}" @selected(old($field['name']) === $option['id'])>{{ $option['title'] }}</option>@endforeach</select>@include('flows.partials.field-error', ['name' => $field['name'], 'helper' => $field['helper_text']])</div>
                        @elseif (in_array($field['type'], ['radio', 'checkbox'], true))
                            <fieldset class="field"><legend>{{ $field['label'] }}</legend>@foreach ($field['options'] as $option)<label class="choice"><input type="{{ $field['type'] }}" name="{{ $field['name'] }}{{ $field['type'] === 'checkbox' ? '[]' : '' }}" value="{{ $option['id'] }}" @checked(in_array($option['id'], (array) old($field['name'], []), true) || old($field['name']) === $option['id']) @required($field['required'] && $loop->first)>{{ $option['title'] }}</label>@endforeach@include('flows.partials.field-error', ['name' => $field['name'], 'helper' => $field['helper_text']])</fieldset>
                        @else
                            <div class="field"><label for="field-{{ $field['id'] }}">{{ $field['label'] }}</label><input id="field-{{ $field['id'] }}" type="{{ ['text' => 'text', 'number' => 'number', 'email' => 'email', 'phone' => 'tel', 'date' => 'date'][$field['type']] ?? 'text' }}" name="{{ $field['name'] }}" value="{{ old($field['name']) }}" @required($field['required'])>@include('flows.partials.field-error', ['name' => $field['name'], 'helper' => $field['helper_text']])</div>
                        @endif
                    @endforeach
                    @if ($stepIndex === count($form['steps']) - 1 && $recaptcha['enabled'])
                        <div class="field">@if ($recaptcha['configured'])<div class="g-recaptcha" data-sitekey="{{ $recaptcha['site_key'] }}"></div>@else<p class="notice">This form is temporarily unavailable. Please try again later.</p>@endif @error('recaptcha')<span class="error">{{ $message }}</span>@enderror</div>
                    @endif
                    <div class="actions">@if ($stepIndex > 0)<button class="secondary" type="button" data-back>Back</button>@else<span></span>@endif @if ($stepIndex < count($form['steps']) - 1)<button type="button" data-next>Next</button>@else<button type="submit" @disabled($recaptcha['enabled'] && ! $recaptcha['configured'])>{{ $form['submit_button'] }}</button>@endif</div>
                </section>
            @endforeach
        </form>
    @endif
</main>
<script>
    (() => { const steps = [...document.querySelectorAll('[data-step]')]; let current = 0; const show = () => steps.forEach((step, index) => step.hidden = index !== current); document.querySelectorAll('[data-next]').forEach(button => button.addEventListener('click', () => { if (steps[current].querySelector(':invalid')) { steps[current].querySelector(':invalid').reportValidity(); return; } current += 1; show(); })); document.querySelectorAll('[data-back]').forEach(button => button.addEventListener('click', () => { current -= 1; show(); })); })();
</script>
</body>
</html>
