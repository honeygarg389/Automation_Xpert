<?php

namespace Tests\Unit\Flows;

use App\Modules\Flows\Models\WhatsappFlow;
use App\Modules\Flows\Services\UnsupportedMetaFlowShapeException;
use App\Modules\Flows\Services\WhatsappFlowJsonCompiler;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * decompile() must be lossless-or-refuse. Before this, a terminal screen was
 * skipped unconditionally: one carrying REAL INPUT FIELDS vanished without an
 * error whenever the rest of the flow was parseable, and a later "Sync Draft to
 * Meta" would then recompile and delete those fields from Meta's draft.
 *
 * The fixtures under tests/Fixtures/MetaFlows are VERBATIM downloads of real
 * FLOW_JSON assets from Meta, not reconstructions — the shapes below are the
 * ones the platform actually met, including one with no `Form` wrapper at all.
 */
class WhatsappFlowDecompileSafetyTest extends TestCase
{
    /** @return array<string, mixed> */
    private function fixture(string $name): array
    {
        return json_decode((string) file_get_contents(base_path("tests/Fixtures/MetaFlows/{$name}.json")), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function field(string $name): array
    {
        return [
            'id' => $name, 'type' => 'text', 'label' => ucfirst($name), 'name' => $name,
            'required' => true, 'helper_text' => null, 'options' => [], 'step' => 1, 'order' => 1,
        ];
    }

    /**
     * A two-step flow in the shape our own compile() emits: two input screens plus SUCCESS.
     *
     * @return array<string, mixed>
     */
    private function ourOwnMultiScreenJson(): array
    {
        $flow = new WhatsappFlow([
            'name' => 'Multi',
            'screens' => [
                ['id' => 'one', 'title' => 'One', 'fields' => [$this->field('first_name')]],
                ['id' => 'two', 'title' => 'Two', 'fields' => [$this->field('email_address')]],
            ],
            'submit_settings' => ['button_text' => 'Send', 'success_message' => 'Thanks.'],
        ]);

        return json_decode((string) json_encode(app(WhatsappFlowJsonCompiler::class)->compile($flow)), true);
    }

    /**
     * @param  array<string, mixed>  $json
     * @return array<string, mixed>
     */
    private function decompile(array $json): array
    {
        return app(WhatsappFlowJsonCompiler::class)->decompile($json);
    }

    // ── The exact mutation repro from the investigation ─────────────────────

    #[Test]
    public function a_terminal_screen_carrying_a_required_input_is_refused_not_silently_dropped(): void
    {
        $json = $this->ourOwnMultiScreenJson();
        $last = count($json['screens']) - 1;
        $this->assertSame('SUCCESS', $json['screens'][$last]['id']);
        array_splice($json['screens'][$last]['layout']['children'][0]['children'], 1, 0, [[
            'type' => 'TextInput', 'name' => 'late_field', 'label' => 'Late field', 'required' => true, 'input-type' => 'text',
        ]]);

        try {
            $result = $this->decompile($json);
            $this->fail('decompile() returned '.count($result['screens']).' step(s) with no error, silently dropping the terminal screen\'s input field.');
        } catch (UnsupportedMetaFlowShapeException $exception) {
            $this->assertStringContainsString('late_field', $exception->getMessage(), 'The reason must name the field that would have been lost.');
            $this->assertStringContainsString('SUCCESS', $exception->getMessage());
        }
    }

    /** POSITIVE CONTROL: the same multi-screen JSON, unmutated, must still decompile and keep both fields. */
    #[Test]
    public function the_unmutated_multi_screen_flow_still_decompiles_and_keeps_every_field(): void
    {
        $result = $this->decompile($this->ourOwnMultiScreenJson());

        $this->assertCount(2, $result['screens']);
        $names = array_merge(...array_map(fn (array $s) => array_column($s['fields'], 'name'), $result['screens']));
        $this->assertSame(['first_name', 'email_address'], $names);
        $this->assertSame('Thanks.', $result['submit_settings']['success_message']);
    }

    // ── The real shapes that used to fail with the generic message ─────────

    #[Test]
    public function a_real_terminal_screen_with_inputs_and_no_form_wrapper_raises_a_specific_reason(): void
    {
        try {
            $this->decompile($this->fixture('terminal_screen_with_inputs_no_form'));
            $this->fail('Flow 6\'s real JSON must be refused.');
        } catch (UnsupportedMetaFlowShapeException $exception) {
            $message = $exception->getMessage();
            $this->assertStringContainsString('FORM_SCREEN', $message);
            $this->assertStringContainsString('final', $message);
            foreach (['client_name', 'phone_1', 'checkbox_46'] as $name) {
                $this->assertStringContainsString($name, $message, "The reason must list {$name}.");
            }
            $this->assertStringNotContainsString('could not be read', $message, 'This is a structural refusal, not a read failure.');
        }
    }

    #[Test]
    public function a_real_display_only_terminal_screen_is_refused_with_the_content_it_would_lose(): void
    {
        try {
            $this->decompile($this->fixture('display_only_terminal_screen'));
            $this->fail('A display-only terminal screen must be refused.');
        } catch (UnsupportedMetaFlowShapeException $exception) {
            $this->assertStringContainsString('WELCOME_SCREEN', $exception->getMessage());
            $this->assertStringContainsString('TextBody', $exception->getMessage());
        }
    }

    #[Test]
    public function the_real_signup_template_is_refused_naming_the_first_unsupported_component(): void
    {
        try {
            $this->decompile($this->fixture('signup_template_subheading_optin_detail_screen'));
            $this->fail('The sign-up template must be refused.');
        } catch (UnsupportedMetaFlowShapeException $exception) {
            $this->assertStringContainsString('TextSubheading', $exception->getMessage());
        }
    }

    #[Test]
    public function a_screen_with_no_form_is_refused_rather_than_read_as_empty(): void
    {
        $json = $this->ourOwnMultiScreenJson();
        // Flatten screen one's Form away: its components now sit directly under the layout.
        $json['screens'][0]['layout']['children'] = $json['screens'][0]['layout']['children'][0]['children'];

        $this->expectException(UnsupportedMetaFlowShapeException::class);
        $this->decompile($json);
    }

    // ── Content outside the Form used to be ignored ─────────────────────────

    #[Test]
    public function content_outside_the_form_is_refused_not_ignored(): void
    {
        $json = $this->ourOwnMultiScreenJson();
        array_unshift($json['screens'][0]['layout']['children'], ['type' => 'TextBody', 'text' => 'Important note above the form.']);

        try {
            $this->decompile($json);
            $this->fail('Content sitting beside the Form would be dropped on the next re-sync.');
        } catch (UnsupportedMetaFlowShapeException $exception) {
            $this->assertStringContainsString('TextBody', $exception->getMessage());
        }
    }

    // ── A terminal screen is only accepted when it is JUST a confirmation ───

    #[Test]
    public function a_terminal_screen_with_extra_content_beyond_its_confirmation_heading_is_refused(): void
    {
        $json = $this->ourOwnMultiScreenJson();
        $last = count($json['screens']) - 1;
        array_splice($json['screens'][$last]['layout']['children'][0]['children'], 1, 0, [
            ['type' => 'TextBody', 'text' => 'Extra paragraph the confirmation must not silently lose.'],
        ]);

        $this->expectException(UnsupportedMetaFlowShapeException::class);
        $this->decompile($json);
    }

    #[Test]
    public function a_plain_confirmation_terminal_screen_is_still_accepted_and_its_heading_becomes_the_success_message(): void
    {
        $result = $this->decompile($this->fixture('own_compiler_shape_control'));

        $this->assertCount(1, $result['screens']);
        $this->assertNotSame('', $result['submit_settings']['success_message']);
    }

    // ── Compatibility ───────────────────────────────────────────────────────

    /** Existing callers catch InvalidArgumentException; the specific type must remain one. */
    #[Test]
    public function the_specific_exception_is_still_an_invalid_argument_exception(): void
    {
        $this->assertInstanceOf(InvalidArgumentException::class, new UnsupportedMetaFlowShapeException('reason'));
    }

    /** A genuinely malformed payload is not "unsupported": it must stay a plain InvalidArgumentException. */
    #[Test]
    public function a_malformed_payload_is_not_reported_as_an_unsupported_shape(): void
    {
        try {
            $this->decompile(['version' => '6.3']);
            $this->fail('A payload with no screens must not decompile.');
        } catch (InvalidArgumentException $exception) {
            $this->assertNotInstanceOf(UnsupportedMetaFlowShapeException::class, $exception);
        }
    }
}
