<?php

namespace Tests\Unit\Flows;

use App\Modules\Flows\Models\WhatsappFlow;
use App\Modules\Flows\Services\WebFormRenderer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WebFormRendererTest extends TestCase
{
    #[Test]
    public function it_transforms_every_shared_screen_field_type_for_a_standard_html_form(): void
    {
        $flow = new WhatsappFlow([
            'name' => 'Website lead form',
            'description' => 'Tell us about yourself.',
            'screens' => [[
                'id' => 'contact',
                'title' => 'Contact details',
                'fields' => [
                    $this->field('heading', '', 'Welcome'),
                    $this->field('text', 'first_name', 'First name'),
                    $this->field('number', 'guests', 'Guests'),
                    $this->field('email', 'email', 'Email'),
                    $this->field('phone', 'phone', 'Phone'),
                    $this->field('textarea', 'notes', 'Notes'),
                    $this->field('select', 'industry', 'Industry', options: [['id' => 'retail', 'title' => 'Retail']]),
                    $this->field('radio', 'contact_method', 'Contact method', options: [['id' => 'email', 'title' => 'Email']]),
                    $this->field('checkbox', 'interests', 'Interests', options: [['id' => 'news', 'title' => 'News']]),
                    $this->field('date', 'start_date', 'Start date'),
                ],
            ]],
            'submit_settings' => [
                'button_text' => 'Send enquiry',
                'success_message' => 'We will be in touch.',
            ],
        ]);

        $form = app(WebFormRenderer::class)->render($flow);

        $this->assertSame('Website lead form', $form['name']);
        $this->assertSame('Tell us about yourself.', $form['description']);
        $this->assertSame('Send enquiry', $form['submit_button']);
        $this->assertSame('We will be in touch.', $form['success_message']);
        $this->assertCount(1, $form['steps']);
        $this->assertSame('Contact details', $form['steps'][0]['title']);
        $this->assertSame(
            ['heading', 'text', 'number', 'email', 'phone', 'textarea', 'select', 'radio', 'checkbox', 'date'],
            array_column($form['steps'][0]['fields'], 'type')
        );
        $this->assertSame('Retail', $form['steps'][0]['fields'][6]['options'][0]['title']);
        $this->assertSame('interests', $form['steps'][0]['fields'][8]['name']);
    }

    /**
     * @param  list<array{id:string,title:string}>  $options
     * @return array{id:string,type:string,label:string,name:string,required:bool,helper_text:null,options:list<array{id:string,title:string}>,step:int,order:int}
     */
    private function field(string $type, string $name, string $label, array $options = []): array
    {
        return [
            'id' => $name === '' ? 'heading' : $name,
            'type' => $type,
            'label' => $label,
            'name' => $name,
            'required' => $type !== 'heading',
            'helper_text' => null,
            'options' => $options,
            'step' => 1,
            'order' => 1,
        ];
    }
}
