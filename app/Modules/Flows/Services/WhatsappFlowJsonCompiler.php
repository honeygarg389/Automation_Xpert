<?php

namespace App\Modules\Flows\Services;

use App\Modules\Flows\Models\WhatsappFlow;
use InvalidArgumentException;

/**
 * Pure compiler from WhatsappFlow's shared `screens` contract to Meta Flow JSON.
 *
 * This class intentionally has no container, persistence, HTTP, or Meta client
 * dependency. Given the same persisted definition it always returns the same
 * JSON-ready array, making it safe for previews and isolated unit tests.
 */
class WhatsappFlowJsonCompiler
{
    public const FLOW_JSON_VERSION = '6.3';

    /** @var list<string> */
    public const FIELD_TYPES = [
        'heading', 'text', 'number', 'email', 'phone', 'textarea',
        'select', 'radio', 'checkbox', 'date',
    ];

    /**
     * @return array<string, mixed>
     */
    public function compile(WhatsappFlow $flow): array
    {
        $steps = $this->normaliseSteps($flow->screens);
        // Task 1 (screenId() fix) — every screen id is computed ONCE, up
        // front, in a single pass with one shared $used set. The old
        // per-call screenId($id, $position) was called TWICE per screen
        // (once for its own 'id', once as the 'next' target from the PRIOR
        // screen's Footer) — a disambiguation scheme needs a stable answer
        // to "have I already assigned this base" that survives both call
        // sites for the exact same screen, which only a precomputed map
        // (not a per-call recomputation) can guarantee.
        $screenIds = $this->buildScreenIds($steps);
        $collected = [];
        $screens = [];

        foreach ($steps as $stepIndex => $step) {
            $screenId = $screenIds[$stepIndex];
            $fields = $step['fields'];
            $inputFields = array_values(array_filter($fields, fn (array $field) => $field['type'] !== 'heading'));
            $children = array_map(fn (array $field) => $this->componentFor($field), $fields);

            $payload = $this->payloadFor($collected, $inputFields);
            $nextScreen = $stepIndex + 1 < count($steps)
                ? $screenIds[$stepIndex + 1]
                : 'SUCCESS';

            $children[] = [
                'type' => 'Footer',
                'label' => $stepIndex + 1 === count($steps)
                    ? (string) ($flow->submit_settings['button_text'] ?? 'Submit')
                    : 'Continue',
                'on-click-action' => [
                    'name' => 'navigate',
                    'next' => ['type' => 'screen', 'name' => $nextScreen],
                    'payload' => $payload,
                ],
            ];

            $screens[] = [
                'id' => $screenId,
                'title' => (string) $step['title'],
                'terminal' => false,
                'data' => $this->dataDefinitions($collected),
                'layout' => [
                    'type' => 'SingleColumnLayout',
                    'children' => [[
                        'type' => 'Form',
                        'name' => 'form_'.strtolower($screenId),
                        'children' => $children,
                    ]],
                ],
            ];

            foreach ($inputFields as $field) {
                $collected[] = $field;
            }
        }

        $successMessage = trim((string) ($flow->submit_settings['success_message'] ?? 'Thank you. Your response has been submitted.'));
        $screens[] = [
            'id' => 'SUCCESS',
            'title' => 'Success',
            'terminal' => true,
            'success' => true,
            'data' => $this->dataDefinitions($collected),
            'layout' => [
                'type' => 'SingleColumnLayout',
                'children' => [[
                    'type' => 'Form',
                    'name' => 'form_success',
                    'children' => [
                        ['type' => 'TextHeading', 'text' => $successMessage],
                        [
                            'type' => 'Footer',
                            'label' => 'Done',
                            'on-click-action' => [
                                'name' => 'complete',
                                'payload' => $this->completePayload($collected),
                            ],
                        ],
                    ],
                ]],
            ],
        ];

        // Section D (imported-flow round-trip fix) — flow-level Meta keys the
        // visual builder has no concept of (Meta's data-exchange/dynamic
        // Flow metadata) are re-emitted unchanged if this Flow was imported
        // with them. Never present for a Flow authored directly in this
        // Builder — only decompile() ever populates $flow->meta_passthrough.
        return [
            'version' => self::FLOW_JSON_VERSION,
            'screens' => $screens,
            ...$this->passthroughFor($flow),
        ];
    }

    /**
     * The flow-level Meta keys this compiler doesn't otherwise model, merged
     * back in only if actually present. Whitelisted deliberately — this is
     * NOT "spread whatever is in the column", so a corrupted or
     * hand-edited meta_passthrough value can never inject an arbitrary
     * top-level key into the compiled output.
     *
     * @return array<string,mixed>
     */
    private function passthroughFor(WhatsappFlow $flow): array
    {
        $passthrough = $flow->meta_passthrough ?? [];

        $result = [];
        foreach (['routing_model', 'data_api_version', 'data_channel_uri'] as $key) {
            if (array_key_exists($key, $passthrough)) {
                $result[$key] = $passthrough[$key];
            }
        }

        return $result;
    }

    /**
     * Reverse the compiler-owned subset of Meta Flow JSON into the shared
     * screens contract. Meta does not retain our field IDs or original screen
     * casing, so compiler-canonical IDs are restored where possible and
     * externally-authored IDs are deterministically derived.
     *
     * Section D — also captures the flow-level Meta keys the visual builder
     * has no editable concept of (routing_model, data_api_version,
     * data_channel_uri: Meta's data-exchange/dynamic-endpoint metadata) into
     * `meta_passthrough`, so a later compile() of the SAME flow (including
     * after a Duplicate) re-emits them unchanged instead of silently
     * dropping them. Absent entirely for the common case of a Flow with none
     * of these keys — callers should treat an empty array as "nothing to
     * persist", not overwrite an existing value with it.
     *
     * @param  array<string, mixed>  $metaFlowJson
     * @return array{screens:list<array{id:string,title:string,fields:list<array<string,mixed>>}>,submit_settings:array{button_text:string,success_message:string},meta_passthrough:array<string,mixed>}
     */
    public function decompile(array $metaFlowJson): array
    {
        $metaScreens = $metaFlowJson['screens'] ?? null;
        if (! is_array($metaScreens) || $metaScreens === []) {
            throw new InvalidArgumentException('Meta Flow JSON must contain at least one screen.');
        }

        $screens = [];
        $submitSettings = [
            'button_text' => 'Submit',
            'success_message' => 'Thank you. Your response has been submitted.',
        ];
        // Section B — tracked across EVERY screen in this Flow (field names
        // must be globally unique per assertField()'s own duplicate-name
        // check), so an already-valid imported name like "customer_name" is
        // preserved untouched, an invalid one is sanitized deterministically,
        // and two different invalid originals that sanitize to the same
        // string never collapse onto one field.
        $usedFieldNames = [];

        foreach ($metaScreens as $screenIndex => $metaScreen) {
            if (! is_array($metaScreen)) {
                throw new InvalidArgumentException('Meta Flow JSON contains an invalid screen.');
            }

            if (($metaScreen['terminal'] ?? false) === true || ($metaScreen['id'] ?? null) === 'SUCCESS') {
                $submitSettings['success_message'] = $this->successMessageFor($metaScreen, $submitSettings['success_message']);

                continue;
            }

            $form = $this->formForScreen($metaScreen);
            $children = $form['children'] ?? null;
            if (! is_array($children)) {
                throw new InvalidArgumentException(sprintf('Meta screen %d has no Form children.', $screenIndex + 1));
            }

            $fields = [];
            $footerLabel = null;
            foreach ($children as $componentIndex => $component) {
                if (! is_array($component)) {
                    continue;
                }
                if (($component['type'] ?? null) === 'Footer') {
                    $footerLabel = is_string($component['label'] ?? null) ? $component['label'] : $footerLabel;

                    continue;
                }
                $fields[] = $this->fieldFromComponent($component, $screenIndex + 1, $componentIndex + 1, count($fields) + 1, $usedFieldNames);
            }

            if ($fields === []) {
                throw new InvalidArgumentException(sprintf('Meta screen %d contains no supported fields.', $screenIndex + 1));
            }
            if ($screenIndex + 1 === count($metaScreens) - 1 && $footerLabel !== null && trim($footerLabel) !== '') {
                $submitSettings['button_text'] = trim($footerLabel);
            }

            $metaId = is_string($metaScreen['id'] ?? null) ? $metaScreen['id'] : 'screen_'.($screenIndex + 1);
            $screens[] = [
                'id' => $this->internalScreenId($metaId, $screenIndex + 1),
                'title' => trim((string) ($metaScreen['title'] ?? 'Step '.($screenIndex + 1))),
                'fields' => $fields,
            ];
        }

        if ($screens === []) {
            throw new InvalidArgumentException('Meta Flow JSON must contain at least one non-terminal screen.');
        }

        $metaPassthrough = [];
        foreach (['routing_model', 'data_api_version', 'data_channel_uri'] as $key) {
            if (array_key_exists($key, $metaFlowJson)) {
                $metaPassthrough[$key] = $metaFlowJson[$key];
            }
        }

        return ['screens' => $screens, 'submit_settings' => $submitSettings, 'meta_passthrough' => $metaPassthrough];
    }

    /**
     * @param  array<string, mixed>  $screen
     * @return array<string, mixed>
     */
    private function formForScreen(array $screen): array
    {
        $children = $screen['layout']['children'] ?? null;
        if (! is_array($children)) {
            throw new InvalidArgumentException('Meta screen layout must contain children.');
        }

        foreach ($children as $child) {
            if (is_array($child) && ($child['type'] ?? null) === 'Form') {
                return $child;
            }
        }

        throw new InvalidArgumentException('Meta screen layout must contain a Form.');
    }

    /**
     * @param  array<string, mixed>  $component
     * @param  array<string,true>  $usedFieldNames  Mutated to record every name returned across the whole decompile() call — see MetaFlowIdentifier::normalize().
     * @return array{id:string,type:string,label:string,name:string,required:bool,helper_text:string|null,options:list<array{id:string,title:string}>,step:int,order:int}
     */
    private function fieldFromComponent(array $component, int $step, int $componentPosition, int $order, array &$usedFieldNames): array
    {
        $metaType = $component['type'] ?? null;
        if ($metaType === 'TextHeading') {
            return [
                'id' => 'heading_'.$step.'_'.$componentPosition,
                'type' => 'heading',
                'label' => trim((string) ($component['text'] ?? 'Heading')),
                'name' => '',
                'required' => false,
                'helper_text' => null,
                'options' => [],
                'step' => $step,
                'order' => $order,
            ];
        }

        $type = match ($metaType) {
            'TextInput' => $this->inputTypeFromComponent($component),
            'TextArea' => 'textarea',
            'Dropdown' => 'select',
            'RadioButtonsGroup' => 'radio',
            'CheckboxGroup' => 'checkbox',
            'DatePicker' => 'date',
            default => throw new InvalidArgumentException('Meta Flow JSON contains unsupported component type '.var_export($metaType, true).'.'),
        };
        $rawName = $component['name'] ?? null;
        if (! is_string($rawName) || $rawName === '') {
            throw new InvalidArgumentException('Every Meta input component requires a name.');
        }
        // Section B — an already-valid Meta name (e.g. "customer_name")
        // survives completely unchanged; an invalid one, or the exact
        // "field_1"-style numeric-suffix shape, is sanitized deterministically
        // with an alphabetic disambiguator on collision. See
        // MetaFlowIdentifier's own docblock for why this is stricter than a
        // plain character-class check.
        $name = MetaFlowIdentifier::normalize($rawName, $usedFieldNames);

        return [
            'id' => $name,
            'type' => $type,
            'label' => trim((string) ($component['label'] ?? $rawName)),
            'name' => $name,
            'required' => (bool) ($component['required'] ?? false),
            'helper_text' => is_string($component['helper-text'] ?? null) ? $component['helper-text'] : null,
            'options' => $this->optionsFromComponent($component, $type),
            'step' => $step,
            'order' => $order,
        ];
    }

    /** @param array<string,mixed> $component */
    private function inputTypeFromComponent(array $component): string
    {
        $inputType = $component['input-type'] ?? 'text';
        if (! is_string($inputType) || ! in_array($inputType, ['text', 'number', 'email', 'phone'], true)) {
            throw new InvalidArgumentException('Meta TextInput has an unsupported input-type.');
        }

        return $inputType;
    }

    /**
     * @param  array<string, mixed>  $component
     * @return list<array{id:string,title:string}>
     */
    private function optionsFromComponent(array $component, string $type): array
    {
        if (! in_array($type, ['select', 'radio', 'checkbox'], true)) {
            return [];
        }
        $source = $component['data-source'] ?? [];
        if (! is_array($source)) {
            throw new InvalidArgumentException('Meta choice component data-source must be an array.');
        }

        return array_values(array_map(function (mixed $option): array {
            if (! is_array($option) || ! is_string($option['id'] ?? null) || ! is_string($option['title'] ?? null)) {
                throw new InvalidArgumentException('Meta choice options require string id and title values.');
            }

            return ['id' => $option['id'], 'title' => $option['title']];
        }, $source));
    }

    /** @param array<string,mixed> $screen */
    private function successMessageFor(array $screen, string $fallback): string
    {
        try {
            foreach ($this->formForScreen($screen)['children'] ?? [] as $component) {
                if (is_array($component) && ($component['type'] ?? null) === 'TextHeading' && is_string($component['text'] ?? null)) {
                    return $component['text'];
                }
            }
        } catch (InvalidArgumentException) {
            return $fallback;
        }

        return $fallback;
    }

    /**
     * Task 1 (screenId() fix) — this regex used to require a trailing
     * `_$position` (matching the old, now-removed numeric position suffix
     * buildScreenIds() no longer emits). Updated to match the current
     * digit-free shape so decompiling OUR OWN freshly-compiled output still
     * recovers the original local screen id (e.g. "SCREEN_IDENTITY" ->
     * "identity") instead of always falling through to the generic
     * 'screen_N' fallback. An externally-authored Meta screen id that
     * happens to share the "SCREEN_" prefix without ever having gone
     * through buildScreenIds() is handled the same way — recovering its
     * suffix is strictly more useful than discarding it.
     */
    private function internalScreenId(string $metaId, int $position): string
    {
        if (preg_match('/^SCREEN_(.+)$/', $metaId, $matches) === 1) {
            return strtolower($matches[1]);
        }

        return 'screen_'.$position;
    }

    /** @param mixed $screens @return list<array{id:string,title:string,fields:list<array<string,mixed>>}> */
    private function normaliseSteps(mixed $screens): array
    {
        if (! is_array($screens) || $screens === []) {
            throw new InvalidArgumentException('A Flow must contain at least one screen.');
        }

        $seenNames = [];
        // Section F — defensive against a hand-edited or corrupted `screens`
        // JSON producing two steps with the same id: screenId() derives the
        // compiled Meta screen ID directly from this local id, so a
        // collision here becomes a guaranteed duplicate Meta screen ID —
        // exactly the kind of locally-detectable mistake that must fail
        // before the Graph API call, with context pointing at which two
        // screens collide.
        $seenScreenIds = [];
        $steps = [];

        foreach ($screens as $stepIndex => $step) {
            if (! is_array($step) || ! is_string($step['id'] ?? null) || trim($step['id']) === '') {
                throw new InvalidArgumentException(sprintf('Screen %d requires a non-empty id.', $stepIndex + 1));
            }
            if (! is_string($step['title'] ?? null) || trim($step['title']) === '') {
                throw new InvalidArgumentException(sprintf('Screen "%s" requires a title.', $step['id']));
            }
            if (! is_array($step['fields'] ?? null) || $step['fields'] === []) {
                throw new InvalidArgumentException(sprintf('Screen "%s" requires at least one field.', $step['id']));
            }
            $screenId = trim($step['id']);
            if (isset($seenScreenIds[$screenId])) {
                throw new InvalidArgumentException(sprintf('Screen "%s": this screen id is used by more than one screen — each screen requires a unique id.', $screenId));
            }
            $seenScreenIds[$screenId] = true;

            $fields = array_values($step['fields']);
            usort($fields, fn (array $a, array $b) => ((int) ($a['order'] ?? 0)) <=> ((int) ($b['order'] ?? 0)));

            $stepTitle = trim((string) $step['title']);
            foreach ($fields as $fieldIndex => &$field) {
                $this->assertField($field, $stepIndex + 1, $stepTitle, $fieldIndex + 1, $seenNames);
                $field['step'] = $stepIndex + 1;
                $field['order'] = $fieldIndex + 1;
            }
            unset($field);

            $steps[] = ['id' => $screenId, 'title' => $stepTitle, 'fields' => $fields];
        }

        if ($seenNames === []) {
            throw new InvalidArgumentException('A Flow must contain at least one input field; headings alone cannot submit data.');
        }

        return $steps;
    }

    /**
     * Section F — error messages name the screen (by its author-facing
     * title, not just a numeric index) and the field, e.g. `Screen "Contact
     * Us": field "email" ...` — not a bare "Invalid JSON" a person would
     * have to guess the cause of.
     *
     * @param  array<string,mixed>  $field
     * @param  array<string,true>  $seenNames
     */
    private function assertField(array &$field, int $step, string $stepTitle, int $position, array &$seenNames): void
    {
        $type = $field['type'] ?? null;
        if (! is_string($type) || ! in_array($type, self::FIELD_TYPES, true)) {
            throw new InvalidArgumentException(sprintf('Screen "%s": field %d has an unsupported type.', $stepTitle, $position));
        }
        if (! is_string($field['id'] ?? null) || trim($field['id']) === '') {
            throw new InvalidArgumentException(sprintf('Screen "%s": field %d requires an id.', $stepTitle, $position));
        }
        if (! is_string($field['label'] ?? null) || trim($field['label']) === '') {
            throw new InvalidArgumentException(sprintf('Screen "%s": field "%s" requires a label.', $stepTitle, $field['id']));
        }
        if ($type === 'heading') {
            return;
        }
        if (! is_string($field['name'] ?? null) || ! MetaFlowIdentifier::isValidName($field['name'])) {
            throw new InvalidArgumentException(sprintf('Screen "%s": field "%s" compiled to an invalid Meta identifier — names must start with a letter and use only letters, numbers, and underscores.', $stepTitle, $field['id']));
        }
        if (isset($seenNames[$field['name']])) {
            throw new InvalidArgumentException(sprintf('Screen "%s": field name "%s" is duplicated across screens.', $stepTitle, $field['name']));
        }
        if (in_array($type, ['select', 'radio', 'checkbox'], true) && (! is_array($field['options'] ?? null) || $field['options'] === [])) {
            throw new InvalidArgumentException(sprintf('Choice field "%s" requires at least one option.', $field['id']));
        }
        $seenNames[$field['name']] = true;
    }

    /** @param array<string,mixed> $field @return array<string,mixed> */
    private function componentFor(array $field): array
    {
        if ($field['type'] === 'heading') {
            return ['type' => 'TextHeading', 'text' => trim((string) $field['label'])];
        }

        $component = [
            'type' => match ($field['type']) {
                'textarea' => 'TextArea',
                'select' => 'Dropdown',
                'radio' => 'RadioButtonsGroup',
                'checkbox' => 'CheckboxGroup',
                'date' => 'DatePicker',
                default => 'TextInput',
            },
            'name' => $field['name'],
            'label' => trim((string) $field['label']),
            'required' => (bool) ($field['required'] ?? false),
        ];

        if (in_array($field['type'], ['text', 'number', 'email', 'phone'], true)) {
            $component['input-type'] = $field['type'] === 'text' ? 'text' : $field['type'];
        }
        if (is_string($field['helper_text'] ?? null) && trim($field['helper_text']) !== '') {
            $component['helper-text'] = trim($field['helper_text']);
        }
        if (in_array($field['type'], ['select', 'radio', 'checkbox'], true)) {
            $component['data-source'] = array_map(function (mixed $option): array {
                if (is_string($option)) {
                    return ['id' => $option, 'title' => $option];
                }
                if (! is_array($option) || ! is_string($option['id'] ?? null) || ! is_string($option['title'] ?? null)) {
                    throw new InvalidArgumentException('Every choice option requires string id and title values.');
                }

                return ['id' => $option['id'], 'title' => $option['title']];
            }, $field['options']);
        }

        return $component;
    }

    /**
     * Section C — Meta's Flow JSON schema requires `screen.data` to be a JSON
     * *object* (`{}` at minimum), never an array. PHP cannot distinguish an
     * empty associative array from an empty list, so `json_encode([])`
     * always produces `[]` regardless of intent — a screen with no prior
     * fields (every Flow's first screen) silently emitted `"data": []`,
     * which Meta rejects. objectOrEmpty() is the single, narrow fix: a
     * non-empty map is untouched (already encodes correctly as `{}`), and
     * only the genuinely-empty case is forced to stdClass so it encodes as
     * `{}` instead. This is deliberately NOT a global array-to-object
     * conversion — options/data-source arrays elsewhere are real Meta
     * arrays and must stay arrays; only the schema locations that are
     * documented Meta *objects* route through this helper.
     *
     * @param  array<string,mixed>  $value
     */
    private function objectOrEmpty(array $value): array|\stdClass
    {
        return $value === [] ? new \stdClass : $value;
    }

    /** @param list<array<string,mixed>> $prior @return array<string,array<string,mixed>>|\stdClass */
    private function dataDefinitions(array $prior): array|\stdClass
    {
        $data = [];
        foreach ($prior as $field) {
            $data[$field['name']] = $field['type'] === 'checkbox'
                ? ['type' => 'array', 'items' => ['type' => 'string'], '__example__' => ['example']]
                : ['type' => 'string', '__example__' => 'example'];
        }

        return $this->objectOrEmpty($data);
    }

    /** @param list<array<string,mixed>> $prior @param list<array<string,mixed>> $current @return array<string,string>|\stdClass */
    private function payloadFor(array $prior, array $current): array|\stdClass
    {
        $payload = [];
        foreach ($prior as $field) {
            $payload[$field['name']] = '${data.'.$field['name'].'}';
        }
        foreach ($current as $field) {
            $payload[$field['name']] = '${form.'.$field['name'].'}';
        }

        return $this->objectOrEmpty($payload);
    }

    /** @param list<array<string,mixed>> $fields @return array<string,string>|\stdClass */
    private function completePayload(array $fields): array|\stdClass
    {
        $payload = [];
        foreach ($fields as $field) {
            $payload[$field['name']] = '${data.'.$field['name'].'}';
        }

        return $this->objectOrEmpty($payload);
    }

    /**
     * Task 1 (screenId() fix) — precomputes every non-SUCCESS screen's
     * compiled Meta id up front. Confirmed via a live Meta validation
     * response ("Property 'id' should only consist of alphabets and
     * underscores", path `screens[N].id`) that the old
     * `'SCREEN_'.$normalised.'_'.$position` shape — an unconditional numeric
     * position suffix on EVERY screen — violated Meta's real schema outright;
     * this was never touched by Section B's earlier identifier fix, which
     * only normalized field/component names, not screen ids.
     *
     * MetaFlowIdentifier::normalizeScreenId() preserves an already-valid
     * local id, sanitizes an invalid one (stripping digits entirely — a
     * screen id's rule is stricter than a field name's), and disambiguates
     * a collision with the alphabetic suffix scheme (a, b, ..., aa, ab, ...)
     * — never digits, so the SUCCESS screen's own literal 'SUCCESS' id
     * (assigned directly in compile(), never routed through this method)
     * can never collide with a generated one either.
     *
     * @param  list<array{id:string,title:string,fields:list<array<string,mixed>>}>  $steps
     * @return list<string>
     */
    private function buildScreenIds(array $steps): array
    {
        // An explicit loop, not array_map(fn (...) => ...) — an arrow
        // function captures $used BY VALUE at closure-creation time, so
        // passing it as normalizeScreenId()'s &$used reference parameter
        // only mutates that one closure's own captured copy, not a value
        // shared across array_map's separate invocations. Caught by this
        // fix's own regression test: two "step_N" screens both came back as
        // "SCREEN_STEP" instead of disambiguating, because the second call
        // never saw the first call's mutation.
        $used = [];
        $ids = [];
        foreach ($steps as $step) {
            $ids[] = MetaFlowIdentifier::normalizeScreenId('SCREEN_'.$step['id'], $used);
        }

        return $ids;
    }
}
