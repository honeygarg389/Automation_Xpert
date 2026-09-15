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
        $collected = [];
        $screens = [];

        foreach ($steps as $stepIndex => $step) {
            $screenId = $this->screenId((string) $step['id'], $stepIndex + 1);
            $fields = $step['fields'];
            $inputFields = array_values(array_filter($fields, fn (array $field) => $field['type'] !== 'heading'));
            $children = array_map(fn (array $field) => $this->componentFor($field), $fields);

            $payload = $this->payloadFor($collected, $inputFields);
            $nextScreen = $stepIndex + 1 < count($steps)
                ? $this->screenId((string) $steps[$stepIndex + 1]['id'], $stepIndex + 2)
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

        return [
            'version' => self::FLOW_JSON_VERSION,
            'screens' => $screens,
        ];
    }

    /**
     * Reverse the compiler-owned subset of Meta Flow JSON into the shared
     * screens contract. Meta does not retain our field IDs or original screen
     * casing, so compiler-canonical IDs are restored where possible and
     * externally-authored IDs are deterministically derived.
     *
     * @param  array<string, mixed>  $metaFlowJson
     * @return array{screens:list<array{id:string,title:string,fields:list<array<string,mixed>>}>,submit_settings:array{button_text:string,success_message:string}}
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
                $fields[] = $this->fieldFromComponent($component, $screenIndex + 1, $componentIndex + 1, count($fields) + 1);
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

        return ['screens' => $screens, 'submit_settings' => $submitSettings];
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
     * @return array{id:string,type:string,label:string,name:string,required:bool,helper_text:string|null,options:list<array{id:string,title:string}>,step:int,order:int}
     */
    private function fieldFromComponent(array $component, int $step, int $componentPosition, int $order): array
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
        $name = $component['name'] ?? null;
        if (! is_string($name) || $name === '') {
            throw new InvalidArgumentException('Every Meta input component requires a name.');
        }

        return [
            'id' => $name,
            'type' => $type,
            'label' => trim((string) ($component['label'] ?? $name)),
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

    private function internalScreenId(string $metaId, int $position): string
    {
        if (preg_match('/^SCREEN_(.+)_'.preg_quote((string) $position, '/').'$/', $metaId, $matches) === 1) {
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

            $fields = array_values($step['fields']);
            usort($fields, fn (array $a, array $b) => ((int) ($a['order'] ?? 0)) <=> ((int) ($b['order'] ?? 0)));

            foreach ($fields as $fieldIndex => &$field) {
                $this->assertField($field, $stepIndex + 1, $fieldIndex + 1, $seenNames);
                $field['step'] = $stepIndex + 1;
                $field['order'] = $fieldIndex + 1;
            }
            unset($field);

            $steps[] = ['id' => trim($step['id']), 'title' => trim($step['title']), 'fields' => $fields];
        }

        if ($seenNames === []) {
            throw new InvalidArgumentException('A Flow must contain at least one input field; headings alone cannot submit data.');
        }

        return $steps;
    }

    /** @param array<string,mixed> $field @param array<string,true> $seenNames */
    private function assertField(array &$field, int $step, int $position, array &$seenNames): void
    {
        $type = $field['type'] ?? null;
        if (! is_string($type) || ! in_array($type, self::FIELD_TYPES, true)) {
            throw new InvalidArgumentException(sprintf('Field %d on screen %d has an unsupported type.', $position, $step));
        }
        if (! is_string($field['id'] ?? null) || trim($field['id']) === '') {
            throw new InvalidArgumentException(sprintf('Field %d on screen %d requires an id.', $position, $step));
        }
        if (! is_string($field['label'] ?? null) || trim($field['label']) === '') {
            throw new InvalidArgumentException(sprintf('Field "%s" requires a label.', $field['id']));
        }
        if ($type === 'heading') {
            return;
        }
        if (! is_string($field['name'] ?? null) || ! preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $field['name'])) {
            throw new InvalidArgumentException(sprintf('Field "%s" requires a name using letters, numbers, and underscores.', $field['id']));
        }
        if (isset($seenNames[$field['name']])) {
            throw new InvalidArgumentException(sprintf('Field name "%s" is duplicated across screens.', $field['name']));
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

    /** @param list<array<string,mixed>> $prior @return array<string,array<string,mixed>> */
    private function dataDefinitions(array $prior): array
    {
        $data = [];
        foreach ($prior as $field) {
            $data[$field['name']] = $field['type'] === 'checkbox'
                ? ['type' => 'array', 'items' => ['type' => 'string'], '__example__' => ['example']]
                : ['type' => 'string', '__example__' => 'example'];
        }

        return $data;
    }

    /** @param list<array<string,mixed>> $prior @param list<array<string,mixed>> $current @return array<string,string> */
    private function payloadFor(array $prior, array $current): array
    {
        $payload = [];
        foreach ($prior as $field) {
            $payload[$field['name']] = '${data.'.$field['name'].'}';
        }
        foreach ($current as $field) {
            $payload[$field['name']] = '${form.'.$field['name'].'}';
        }

        return $payload;
    }

    /** @param list<array<string,mixed>> $fields @return array<string,string> */
    private function completePayload(array $fields): array
    {
        $payload = [];
        foreach ($fields as $field) {
            $payload[$field['name']] = '${data.'.$field['name'].'}';
        }

        return $payload;
    }

    private function screenId(string $id, int $position): string
    {
        $normalised = strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '_', trim($id)));

        return 'SCREEN_'.trim($normalised, '_').'_'.$position;
    }
}
