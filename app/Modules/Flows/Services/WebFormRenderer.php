<?php

namespace App\Modules\Flows\Services;

use App\Modules\Flows\Models\WhatsappFlow;

/**
 * Transforms the platform-owned `screens` contract into ordinary browser-form
 * view data. It intentionally never invokes the Meta compiler: Meta Flow JSON
 * and HTML have different transport concerns, but share the same authoring
 * definition.
 */
class WebFormRenderer
{
    /** @var list<string> */
    private const FIELD_TYPES = [
        'heading', 'text', 'number', 'email', 'phone', 'textarea', 'select', 'radio', 'checkbox', 'date',
    ];

    /**
     * @return array{
     *   name:string,
     *   description:string|null,
     *   submit_button:string,
     *   success_message:string,
     *   steps:list<array{id:string,title:string,fields:list<array{id:string,type:string,label:string,name:string,required:bool,helper_text:string|null,options:list<array{id:string,title:string}>}>}>
     * }
     */
    public function render(WhatsappFlow $flow): array
    {
        // screens: the column is NOT NULL and the model's own docblock
        // guarantees a list here — no defensive check needed.
        $screens = $flow->screens;
        // submit_settings: the column IS nullable (unlike screens), so a
        // flow created without explicit settings genuinely has null here.
        $settings = $flow->submit_settings ?? [];

        return [
            'name' => $flow->name,
            'description' => $flow->description,
            'submit_button' => $this->stringOr($settings['button_text'] ?? null, 'Submit'),
            'success_message' => $this->stringOr($settings['success_message'] ?? null, 'Thank you. Your response has been submitted.'),
            // array_map with two array arguments always reindexes sequentially,
            // so this is already a list — no array_values() needed.
            'steps' => array_map(fn (mixed $screen, int $position): array => $this->step($screen, $position), $screens, array_keys($screens)),
        ];
    }

    /** @return list<array{id:string,type:string,label:string,name:string,required:bool,helper_text:string|null,options:list<array{id:string,title:string}>}> */
    public function fields(WhatsappFlow $flow): array
    {
        return array_merge(...array_map(
            fn (array $step): array => $step['fields'],
            $this->render($flow)['steps'],
        ));
    }

    /** @return array{id:string,title:string,fields:list<array{id:string,type:string,label:string,name:string,required:bool,helper_text:string|null,options:list<array{id:string,title:string}>}>} */
    private function step(mixed $screen, int $position): array
    {
        $screen = is_array($screen) ? $screen : [];
        $fields = is_array($screen['fields'] ?? null) ? $screen['fields'] : [];

        usort($fields, fn (mixed $left, mixed $right): int => (int) (is_array($left) ? ($left['order'] ?? 0) : 0) <=> (int) (is_array($right) ? ($right['order'] ?? 0) : 0));

        return [
            'id' => $this->stringOr($screen['id'] ?? null, 'step_'.($position + 1)),
            'title' => $this->stringOr($screen['title'] ?? null, 'Step '.($position + 1)),
            // usort() above already reindexes to a sequential list — no
            // array_values() needed on top of a single-array array_map().
            'fields' => array_map(fn (mixed $field): array => $this->field($field), $fields),
        ];
    }

    /** @return array{id:string,type:string,label:string,name:string,required:bool,helper_text:string|null,options:list<array{id:string,title:string}>} */
    private function field(mixed $field): array
    {
        $field = is_array($field) ? $field : [];
        $type = $this->stringOr($field['type'] ?? null, 'text');

        if (! in_array($type, self::FIELD_TYPES, true)) {
            $type = 'text';
        }

        $options = is_array($field['options'] ?? null) ? $field['options'] : [];

        return [
            'id' => $this->stringOr($field['id'] ?? null, $this->stringOr($field['name'] ?? null, 'field')),
            'type' => $type,
            'label' => $this->stringOr($field['label'] ?? null, 'Field'),
            'name' => $type === 'heading' ? '' : $this->stringOr($field['name'] ?? null, ''),
            'required' => $type !== 'heading' && (bool) ($field['required'] ?? false),
            'helper_text' => is_string($field['helper_text'] ?? null) ? $field['helper_text'] : null,
            'options' => array_values(array_map(fn (mixed $option): array => [
                'id' => $this->stringOr(is_array($option) ? ($option['id'] ?? null) : null, ''),
                'title' => $this->stringOr(is_array($option) ? ($option['title'] ?? null) : null, ''),
            ], $options)),
        ];
    }

    private function stringOr(mixed $value, string $fallback): string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : $fallback;
    }
}
