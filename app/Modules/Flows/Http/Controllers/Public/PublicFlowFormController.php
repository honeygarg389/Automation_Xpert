<?php

namespace App\Modules\Flows\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Modules\Flows\Models\WhatsappFlow;
use App\Modules\Flows\Services\WebFormRenderer;
use App\Modules\Flows\Services\WebFormSubmissionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;

/** Public, CSRF-protected HTML form surface for an explicitly enabled Flow. */
class PublicFlowFormController extends Controller
{
    public function __construct(
        private readonly WebFormRenderer $renderer,
        private readonly WebFormSubmissionService $submissions,
    ) {}

    public function show(string $slug): Response
    {
        $flow = $this->flow($slug);
        $form = $this->renderer->render($flow);
        $siteKey = (string) config('services.recaptcha.site_key', '');

        return response()->view('flows.public-form', [
            'flow' => $flow,
            'form' => $form,
            'successMessage' => session('web_form_success'),
            'recaptcha' => [
                'enabled' => $flow->recaptcha_enabled,
                'configured' => $siteKey !== '',
                'site_key' => $siteKey,
            ],
        ]);
    }

    public function submit(Request $request, string $slug): RedirectResponse
    {
        $flow = $this->flow($slug);

        if ($flow->recaptcha_enabled && ! $this->passesRecaptcha($request)) {
            return back()
                ->withInput()
                ->withErrors(['recaptcha' => 'Please complete the security check and try again.']);
        }

        $answers = $request->validate($this->rules($this->renderer->fields($flow)));
        $this->submissions->store(
            $flow,
            $answers,
            $request->ip(),
            $this->nullableUserAgent($request->userAgent()),
        );

        return to_route('public.flows.form.show', $flow->public_slug)
            ->with('web_form_success', $this->renderer->render($flow)['success_message']);
    }

    private function flow(string $slug): WhatsappFlow
    {
        // Public discovery happens before a tenant context exists. The disabled
        // predicate belongs in the query so disabled and unknown slugs share an
        // indistinguishable 404 response.
        //
        // reason: the workspace is the ANSWER this lookup is resolving, not
        // something known beforehand — a visitor to a public form URL carries
        // no session and no tenant. The 128-bit random public_slug (see
        // WhatsappFlow::generatePublicSlug()) is the routing boundary instead,
        // the same discovery shape as WhatsappFlowKeyPair's endpoint token and
        // SmartQrRedirectResolver's scan token: an unguessable opaque value
        // stands in for ambient context precisely because none exists yet.
        // Scoped, this query would fail CLOSED and every public form — even a
        // correctly enabled one — would 404, which is the mirror image of the
        // bug this bypass exists to avoid.
        return WhatsappFlow::query()
            ->withoutWorkspaceScope('reason: public web-form lookup by opaque public_slug, before any tenant context exists — see WhatsappFlowKeyPair/SmartQrRedirectResolver precedent')
            ->where('public_slug', $slug)
            ->where('web_form_enabled', true)
            ->firstOrFail();
    }

    /**
     * @param  list<array{id:string,type:string,label:string,name:string,required:bool,helper_text:string|null,options:list<array{id:string,title:string}>}>  $fields
     * @return array<string, list<mixed>>
     */
    private function rules(array $fields): array
    {
        $rules = [];

        foreach ($fields as $field) {
            $name = $field['name'];
            if ($name === '') {
                continue;
            }

            $required = $field['required'] ? 'required' : 'nullable';
            $type = $field['type'];
            $choices = array_values(array_filter(array_column($field['options'], 'id'), static fn (mixed $id): bool => $id !== ''));

            $rules[$name] = match ($type) {
                'number' => [$required, 'numeric'],
                'email' => [$required, 'string', 'email:rfc', 'max:255'],
                'textarea' => [$required, 'string', 'max:10000'],
                'date' => [$required, 'date'],
                'select', 'radio' => [$required, 'string', Rule::in($choices)],
                'checkbox' => [$required, 'array', ...($field['required'] ? ['min:1'] : [])],
                default => [$required, 'string', 'max:255'],
            };

            if ($type === 'checkbox') {
                $rules[$name.'.*'] = ['string', Rule::in($choices)];
            }
        }

        return $rules;
    }

    private function passesRecaptcha(Request $request): bool
    {
        $secret = (string) config('services.recaptcha.secret_key', '');
        $token = (string) $request->input('g-recaptcha-response', '');

        if ($secret === '' || $token === '') {
            return false;
        }

        try {
            $response = Http::asForm()
                ->timeout(10)
                ->post((string) config('services.recaptcha.verify_url'), [
                    'secret' => $secret,
                    'response' => $token,
                    'remoteip' => $request->ip(),
                ]);
        } catch (\Throwable) {
            return false;
        }

        return $response->successful() && $response->json('success') === true;
    }

    private function nullableUserAgent(?string $userAgent): ?string
    {
        if ($userAgent === null || $userAgent === '') {
            return null;
        }

        return mb_substr($userAgent, 0, 512);
    }
}
