<?php

namespace App\Modules\Restaurant\Services;

use App\Modules\Restaurant\Models\RestaurantFeedbackRequest;
use App\Modules\Restaurant\Support\RestaurantOutboundDecision;
use App\Modules\Whatsapp\Models\WhatsappTemplate;
use Illuminate\Routing\UrlGenerator;

/** Compiles only the opaque suffix for a structurally approved Feedback URL button. */
final class FeedbackRequestTemplatePayloadCompiler
{
    public const REASON_BODY_VARIABLES = 'feedback_template_body_variables';

    public const REASON_URL_BUTTON_MISSING = 'feedback_template_url_button_missing';

    public const REASON_URL_BUTTON_MULTIPLE = 'feedback_template_url_button_multiple';

    public const REASON_URL_BUTTON_STATIC = 'feedback_template_url_button_static';

    public const REASON_URL_BUTTON_INVALID = 'feedback_template_url_button_invalid';

    public function __construct(private readonly UrlGenerator $urls) {}

    /** @return array<int, array<string, mixed>>|RestaurantOutboundDecision */
    public function compile(WhatsappTemplate $template, RestaurantFeedbackRequest $request): array|RestaurantOutboundDecision
    {
        if (preg_match('/^[a-f0-9]{64}$/', $request->public_token) !== 1) {
            return $this->block($request, 'feedback_public_token_invalid');
        }
        if ($this->urls->route('public.restaurant.feedback.show', ['token' => $request->public_token], false) !== '/f/'.$request->public_token) {
            return $this->block($request, self::REASON_URL_BUTTON_INVALID);
        }
        $buttons = [];
        foreach ($template->components ?? [] as $component) {
            if (($component['type'] ?? null) === 'BODY' && preg_match('/{{\s*\d+\s*}}/', (string) ($component['text'] ?? '')) === 1) {
                return $this->block($request, self::REASON_BODY_VARIABLES);
            }
            if (($component['type'] ?? null) !== 'BUTTONS' || ! is_array($component['buttons'] ?? null)) {
                continue;
            }
            foreach ($component['buttons'] as $index => $button) {
                if (is_array($button) && ($button['type'] ?? null) === 'URL') {
                    $buttons[] = [$index, $button];
                }
            }
        }
        if ($buttons === []) {
            return $this->block($request, self::REASON_URL_BUTTON_MISSING);
        }
        if (count($buttons) !== 1) {
            return $this->block($request, self::REASON_URL_BUTTON_MULTIPLE);
        }
        [$index, $button] = $buttons[0];
        $url = $button['url'] ?? null;
        if (! is_string($url) || ! str_contains($url, '{{1}}')) {
            return $this->block($request, self::REASON_URL_BUTTON_STATIC);
        }
        if ($url !== $this->expectedButtonUrl('/f')) {
            return $this->block($request, self::REASON_URL_BUTTON_INVALID);
        }

        return [['type' => 'button', 'sub_type' => 'url', 'index' => (string) $index, 'parameters' => [['type' => 'text', 'text' => $request->public_token]]]];
    }

    private function block(RestaurantFeedbackRequest $request, string $reason): RestaurantOutboundDecision
    {
        return RestaurantOutboundDecision::block($request->purpose, $reason, $request->workspace_id, $request->restaurant_bill_id, $request->outlet_id);
    }

    private function expectedButtonUrl(string $path): ?string
    {
        // The URL host comes only from trusted deployment configuration. Meta
        // templates are static except for {{1}}, so a template from another
        // environment would point a valid token at the wrong database.
        $baseUrl = rtrim((string) config('app.url'), '/');
        $parts = parse_url($baseUrl);
        if ($baseUrl === '' || ! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || ! isset($parts['host']) || isset($parts['query'], $parts['fragment'], $parts['user'], $parts['pass'])) {
            return null;
        }

        return $baseUrl.$path.'/{{1}}';
    }
}
