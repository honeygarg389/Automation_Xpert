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
        $this->assertSame('SCREEN_CONTACT_1', $compiled['screens'][0]['id']);
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
}
