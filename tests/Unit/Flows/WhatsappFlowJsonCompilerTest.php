<?php

namespace Tests\Unit\Flows;

use App\Modules\Flows\Models\WhatsappFlow;
use App\Modules\Flows\Services\WhatsappFlowJsonCompiler;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WhatsappFlowJsonCompilerTest extends TestCase
{
    private function flow(array $screens, array $submit = []): WhatsappFlow
    {
        return new WhatsappFlow([
            'name' => 'Test Flow',
            'screens' => $screens,
            'submit_settings' => $submit,
        ]);
    }

    private function field(string $id, string $type, string $label, ?string $name = null, array $extra = []): array
    {
        return array_merge([
            'id' => $id, 'type' => $type, 'label' => $label,
            'name' => $name ?? $id, 'required' => true, 'helper_text' => 'Helpful hint',
            'options' => [], 'step' => 1, 'order' => 1,
        ], $extra);
    }

    #[Test]
    public function a_single_step_form_compiles_to_a_meta_flow_with_success_screen(): void
    {
        $compiled = app(WhatsappFlowJsonCompiler::class)->compile($this->flow([[
            'id' => 'contact', 'title' => 'Contact details',
            'fields' => [$this->field('name', 'text', 'Your name')],
        ]], ['button_text' => 'Send', 'success_message' => 'We have it.']));

        $this->assertSame('6.3', $compiled['version']);
        $this->assertCount(2, $compiled['screens']);
        // Task 1 — no numeric position suffix anymore (Meta rejects digits
        // anywhere in a screen id); "contact" is already valid so it's
        // preserved as-is under the "SCREEN_" prefix, uppercased.
        $this->assertSame('SCREEN_CONTACT', $compiled['screens'][0]['id']);
        $this->assertFalse($compiled['screens'][0]['terminal']);
        $children = $compiled['screens'][0]['layout']['children'][0]['children'];
        $this->assertSame('Form', $compiled['screens'][0]['layout']['children'][0]['type']);
        $this->assertSame('TextInput', $children[0]['type']);
        $this->assertSame('text', $children[0]['input-type']);
        $this->assertSame('Send', $children[1]['label']);
        $this->assertSame('SUCCESS', $children[1]['on-click-action']['next']['name']);
        $this->assertSame('${form.name}', $children[1]['on-click-action']['payload']['name']);

        $success = $compiled['screens'][1];
        $this->assertSame('SUCCESS', $success['id']);
        $this->assertTrue($success['terminal']);
        $this->assertTrue($success['success']);
        $this->assertSame('We have it.', $success['layout']['children'][0]['children'][0]['text']);
        $this->assertSame('complete', $success['layout']['children'][0]['children'][1]['on-click-action']['name']);
        $this->assertSame('${data.name}', $success['layout']['children'][0]['children'][1]['on-click-action']['payload']['name']);
    }

    #[Test]
    public function a_three_step_form_carries_all_prior_data_forward_and_completes_with_it(): void
    {
        $compiled = app(WhatsappFlowJsonCompiler::class)->compile($this->flow([
            ['id' => 'identity', 'title' => 'Identity', 'fields' => [$this->field('first_name', 'text', 'First name')]],
            ['id' => 'contact', 'title' => 'Contact', 'fields' => [$this->field('email', 'email', 'Email')]],
            ['id' => 'preferences', 'title' => 'Preferences', 'fields' => [$this->field('day', 'date', 'Preferred day')]],
        ]));

        $firstFooter = $compiled['screens'][0]['layout']['children'][0]['children'][1];
        $this->assertSame(['first_name' => '${form.first_name}'], $firstFooter['on-click-action']['payload']);

        $second = $compiled['screens'][1];
        $this->assertArrayHasKey('first_name', $second['data']);
        $secondFooter = $second['layout']['children'][0]['children'][1];
        $this->assertSame('${data.first_name}', $secondFooter['on-click-action']['payload']['first_name']);
        $this->assertSame('${form.email}', $secondFooter['on-click-action']['payload']['email']);

        $third = $compiled['screens'][2];
        $this->assertArrayHasKey('first_name', $third['data']);
        $this->assertArrayHasKey('email', $third['data']);
        $thirdFooter = $third['layout']['children'][0]['children'][1];
        $this->assertSame('${data.first_name}', $thirdFooter['on-click-action']['payload']['first_name']);
        $this->assertSame('${data.email}', $thirdFooter['on-click-action']['payload']['email']);
        $this->assertSame('${form.day}', $thirdFooter['on-click-action']['payload']['day']);

        $complete = $compiled['screens'][3]['layout']['children'][0]['children'][1]['on-click-action']['payload'];
        $this->assertSame([
            'first_name' => '${data.first_name}', 'email' => '${data.email}', 'day' => '${data.day}',
        ], $complete);
    }

    #[Test]
    public function every_supported_field_type_maps_to_its_meta_component_and_attributes(): void
    {
        $fields = [
            $this->field('heading', 'heading', 'About you', '', ['required' => false]),
            $this->field('text', 'text', 'Text'),
            $this->field('number', 'number', 'Number'),
            $this->field('email', 'email', 'Email'),
            $this->field('phone', 'phone', 'Phone'),
            $this->field('textarea', 'textarea', 'Notes'),
            $this->field('select', 'select', 'Select', null, ['options' => ['one', ['id' => 'two', 'title' => 'Two']]]),
            $this->field('radio', 'radio', 'Radio', null, ['options' => ['yes']]),
            $this->field('checkbox', 'checkbox', 'Checkbox', null, ['options' => ['agree']]),
            $this->field('date', 'date', 'Date'),
        ];
        foreach ($fields as $index => &$field) {
            $field['order'] = $index + 1;
        }
        unset($field);

        $children = app(WhatsappFlowJsonCompiler::class)->compile($this->flow([[
            'id' => 'all', 'title' => 'All fields', 'fields' => $fields,
        ]]))['screens'][0]['layout']['children'][0]['children'];

        $this->assertSame(['TextHeading', 'TextInput', 'TextInput', 'TextInput', 'TextInput', 'TextArea', 'Dropdown', 'RadioButtonsGroup', 'CheckboxGroup', 'DatePicker'], array_column(array_slice($children, 0, 10), 'type'));
        $this->assertSame(['text', 'number', 'email', 'phone'], array_column(array_slice($children, 1, 4), 'input-type'));
        $this->assertSame([['id' => 'one', 'title' => 'one'], ['id' => 'two', 'title' => 'Two']], $children[6]['data-source']);
        $this->assertSame('Helpful hint', $children[9]['helper-text']);
    }

    #[Test]
    public function empty_or_invalid_definitions_fail_with_clear_errors(): void
    {
        $compiler = app(WhatsappFlowJsonCompiler::class);

        try {
            $compiler->compile($this->flow([]));
            $this->fail('An empty definition must not compile.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('A Flow must contain at least one screen.', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unsupported type');
        $compiler->compile($this->flow([[
            'id' => 'invalid', 'title' => 'Invalid', 'fields' => [$this->field('bad', 'map', 'Bad')],
        ]]));
    }

    #[Test]
    public function compiler_owned_multi_step_json_round_trips_through_decompile(): void
    {
        $screens = [
            ['id' => 'identity', 'title' => 'Identity', 'fields' => [
                $this->field('heading_1_1', 'heading', 'About you', '', ['required' => false, 'helper_text' => null]),
                $this->field('first_name', 'text', 'First name', 'first_name', ['order' => 2]),
            ]],
            ['id' => 'preferences', 'title' => 'Preferences', 'fields' => [
                $this->field('channels', 'checkbox', 'Channels', 'channels', [
                    'step' => 2,
                    'options' => [['id' => 'email', 'title' => 'Email'], ['id' => 'whatsapp', 'title' => 'WhatsApp']],
                ]),
            ]],
        ];
        $submit = ['button_text' => 'Finish', 'success_message' => 'All set.'];
        $compiler = app(WhatsappFlowJsonCompiler::class);

        $decompiled = $compiler->decompile($compiler->compile($this->flow($screens, $submit)));

        $this->assertSame($screens, $decompiled['screens']);
        $this->assertSame($submit, $decompiled['submit_settings']);
    }

    /**
     * Task 1 (screenId() fix), regression — reproduces the exact real-world
     * shape confirmed live against Meta's API in the diagnosis session: a
     * flow whose screen uses the Builder's own default local id "step_1"
     * (Builder.jsx's blankStep()) compiled to "SCREEN_STEP_1_1", which Meta's
     * real validation response rejected outright: "Property 'id' should
     * only consist of alphabets and underscores" at path `screens[0].id`.
     * Confirmed against flow id=6 ("Lead", the already-Published flow used
     * in that diagnosis) — its current content uses this exact "step_1"
     * default, and compiling it with today's fix must never reproduce the
     * old digit-suffixed shape anywhere.
     */
    #[Test]
    public function a_flow_using_the_default_step_1_local_screen_id_never_compiles_to_a_digit_suffixed_screen_id(): void
    {
        $compiled = app(WhatsappFlowJsonCompiler::class)->compile($this->flow([[
            'id' => 'step_1', 'title' => 'Step 1', 'fields' => [$this->field('name', 'text', 'Name')],
        ]]));

        $screenId = $compiled['screens'][0]['id'];
        $this->assertSame('SCREEN_STEP', $screenId);
        $this->assertDoesNotMatchRegularExpression('/[0-9]/', $screenId, 'No digit may appear anywhere in a compiled screen id.');
        $this->assertNotSame('SCREEN_STEP_1_1', $screenId, 'The exact real-world violation confirmed live against Meta must never reappear.');
    }

    /**
     * Multiple steps that all use the Builder's default "step_N" naming
     * (the overwhelmingly common case — the UI never exposes an editable
     * screen id, only a title) all sanitize to the SAME base ("SCREEN_STEP")
     * and must disambiguate alphabetically, never by falling back to the
     * digit each one originally differed by.
     */
    #[Test]
    public function multiple_default_named_steps_disambiguate_alphabetically_with_no_digits_anywhere(): void
    {
        $compiled = app(WhatsappFlowJsonCompiler::class)->compile($this->flow([
            ['id' => 'step_1', 'title' => 'Step 1', 'fields' => [$this->field('first_name', 'text', 'First name')]],
            ['id' => 'step_2', 'title' => 'Step 2', 'fields' => [$this->field('email', 'email', 'Email')]],
        ]));

        $ids = array_column(array_slice($compiled['screens'], 0, 2), 'id');
        $this->assertSame(['SCREEN_STEP', 'SCREEN_STEP_A'], $ids);
        foreach ($ids as $id) {
            $this->assertDoesNotMatchRegularExpression('/[0-9]/', $id);
        }
        // Navigation must reference the SAME disambiguated id, not the old digit-suffixed one.
        $firstFooter = $compiled['screens'][0]['layout']['children'][0]['children'][1];
        $this->assertSame('SCREEN_STEP_A', $firstFooter['on-click-action']['next']['name']);
    }

    #[Test]
    public function a_hand_written_meta_flow_json_fixture_decompiles_into_the_shared_screens_contract(): void
    {
        // This fixture is deliberately not produced by compile(). It represents
        // the component/layout shape returned from a Meta FLOW_JSON asset.
        $metaFlowJson = [
            'version' => '6.3',
            'screens' => [
                [
                    'id' => 'PERSONAL_DETAILS',
                    'title' => 'Tell us about yourself',
                    'terminal' => false,
                    'layout' => [
                        'type' => 'SingleColumnLayout',
                        'children' => [[
                            'type' => 'Form',
                            'name' => 'personal_details_form',
                            'children' => [
                                ['type' => 'TextHeading', 'text' => 'Personal details'],
                                [
                                    'type' => 'TextInput',
                                    'name' => 'first_name',
                                    'label' => 'First name',
                                    'input-type' => 'text',
                                    'required' => true,
                                    'helper-text' => 'As shown on your ID',
                                ],
                                [
                                    'type' => 'Dropdown',
                                    'name' => 'preferred_channel',
                                    'label' => 'Preferred channel',
                                    'required' => false,
                                    'data-source' => [
                                        ['id' => 'email', 'title' => 'Email'],
                                        ['id' => 'whatsapp', 'title' => 'WhatsApp'],
                                    ],
                                ],
                                [
                                    'type' => 'Footer',
                                    'label' => 'Send response',
                                    'on-click-action' => [
                                        'name' => 'navigate',
                                        'next' => ['type' => 'screen', 'name' => 'SUCCESS'],
                                        'payload' => [
                                            'first_name' => '${form.first_name}',
                                            'preferred_channel' => '${form.preferred_channel}',
                                        ],
                                    ],
                                ],
                            ],
                        ]],
                    ],
                ],
                [
                    'id' => 'SUCCESS',
                    'title' => 'Success',
                    'terminal' => true,
                    'success' => true,
                    'layout' => [
                        'type' => 'SingleColumnLayout',
                        'children' => [[
                            'type' => 'Form',
                            'name' => 'success_form',
                            'children' => [
                                ['type' => 'TextHeading', 'text' => 'Thanks — we will be in touch.'],
                                [
                                    'type' => 'Footer',
                                    'label' => 'Done',
                                    'on-click-action' => ['name' => 'complete', 'payload' => []],
                                ],
                            ],
                        ]],
                    ],
                ],
            ],
        ];

        $decompiled = app(WhatsappFlowJsonCompiler::class)->decompile($metaFlowJson);

        $this->assertSame([
            ['id' => 'screen_1', 'title' => 'Tell us about yourself', 'fields' => [
                ['id' => 'heading_1_1', 'type' => 'heading', 'label' => 'Personal details', 'name' => '', 'required' => false, 'helper_text' => null, 'options' => [], 'step' => 1, 'order' => 1],
                ['id' => 'first_name', 'type' => 'text', 'label' => 'First name', 'name' => 'first_name', 'required' => true, 'helper_text' => 'As shown on your ID', 'options' => [], 'step' => 1, 'order' => 2],
                ['id' => 'preferred_channel', 'type' => 'select', 'label' => 'Preferred channel', 'name' => 'preferred_channel', 'required' => false, 'helper_text' => null, 'options' => [
                    ['id' => 'email', 'title' => 'Email'], ['id' => 'whatsapp', 'title' => 'WhatsApp'],
                ], 'step' => 1, 'order' => 3],
            ]],
        ], $decompiled['screens']);
        $this->assertSame([
            'button_text' => 'Send response',
            'success_message' => 'Thanks — we will be in touch.',
        ], $decompiled['submit_settings']);
    }
}
