<?php

namespace Tests\Unit\Flows;

use App\Modules\Flows\Services\MetaFlowIdentifier;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MetaFlowIdentifierTest extends TestCase
{
    #[Test]
    public function alphabetic_suffixes_never_contain_a_digit_and_follow_a_then_z_then_aa(): void
    {
        $this->assertSame('a', MetaFlowIdentifier::alphabeticSuffix(0));
        $this->assertSame('b', MetaFlowIdentifier::alphabeticSuffix(1));
        $this->assertSame('z', MetaFlowIdentifier::alphabeticSuffix(25));
        $this->assertSame('aa', MetaFlowIdentifier::alphabeticSuffix(26));
        $this->assertSame('ab', MetaFlowIdentifier::alphabeticSuffix(27));

        for ($i = 0; $i < 100; $i++) {
            $this->assertDoesNotMatchRegularExpression('/\d/', MetaFlowIdentifier::alphabeticSuffix($i));
        }
    }

    #[Test]
    public function an_already_valid_identifier_is_preserved_completely_unchanged(): void
    {
        $used = [];
        $this->assertSame('customer_name', MetaFlowIdentifier::normalize('customer_name', $used));
        $this->assertSame('email2', MetaFlowIdentifier::normalize('email2', $used), 'Digits already present in an imported name are not a violation — only a GENERATED bare numeric suffix is.');
    }

    #[Test]
    public function an_invalid_identifier_is_sanitized_deterministically(): void
    {
        $used = [];
        $this->assertSame('customer_name', MetaFlowIdentifier::normalize('customer-name-1', $used), 'The trailing numeric suffix is stripped, not preserved as digits.');

        $used = [];
        $this->assertSame('field', MetaFlowIdentifier::normalize('123', $used), 'A purely numeric candidate gets the fallback base prefixed, then its own trailing-numeric shape is stripped too.');
    }

    #[Test]
    public function the_exact_field_1_placeholder_shape_is_never_preserved_even_though_it_passes_the_general_character_class(): void
    {
        $used = [];
        $this->assertSame('field', MetaFlowIdentifier::normalize('field_1', $used), '"field_1" matches [A-Za-z][A-Za-z0-9_]* generally, but the trailing numeric-suffix shape must still be eliminated.');

        $used = [];
        $this->assertSame('field', MetaFlowIdentifier::normalize('field_1_1', $used), 'Repeated trailing numeric segments are stripped in one pass.');
    }

    #[Test]
    public function two_different_invalid_originals_that_sanitize_to_the_same_string_never_collapse(): void
    {
        $used = [];
        $first = MetaFlowIdentifier::normalize('customer-name', $used);
        $second = MetaFlowIdentifier::normalize('customer_name!!!', $used);

        $this->assertSame('customer_name', $first);
        $this->assertNotSame($first, $second);
        $this->assertMatchesRegularExpression('/^customer_name_[a-z]+$/', $second, 'The disambiguation suffix must be alphabetic, never numeric.');
    }

    #[Test]
    public function next_unique_returns_the_bare_base_first_and_only_suffixes_from_the_second_call_onward(): void
    {
        $used = [];
        $this->assertSame('field', MetaFlowIdentifier::nextUnique($used));
        $this->assertSame('field_a', MetaFlowIdentifier::nextUnique($used));
        $this->assertSame('field_b', MetaFlowIdentifier::nextUnique($used));
    }

    #[Test]
    public function is_valid_name_matches_metas_alphabetic_start_rule(): void
    {
        $this->assertTrue(MetaFlowIdentifier::isValidName('customer_name'));
        $this->assertTrue(MetaFlowIdentifier::isValidName('a1'));
        $this->assertFalse(MetaFlowIdentifier::isValidName('1field'));
        $this->assertFalse(MetaFlowIdentifier::isValidName('field-name'));
        $this->assertFalse(MetaFlowIdentifier::isValidName(''));
    }

    /**
     * Task 1 (screenId() fix) — screen ids are held to Meta's STRICTER real
     * rule confirmed via a live validation response: alphabets and
     * underscores ONLY, no digits anywhere at all (narrower than
     * isValidName(), which allows embedded digits for component names).
     */
    #[Test]
    public function is_valid_screen_id_rejects_any_digit_anywhere(): void
    {
        $this->assertTrue(MetaFlowIdentifier::isValidScreenId('CONTACT_US'));
        $this->assertFalse(MetaFlowIdentifier::isValidScreenId('SCREEN_STEP_1_1'), 'Digits anywhere, not just a trailing suffix, are invalid for a screen id.');
        $this->assertFalse(MetaFlowIdentifier::isValidScreenId('STEP2'), 'Unlike a field name, an EMBEDDED digit is also invalid here.');
        $this->assertFalse(MetaFlowIdentifier::isValidScreenId(''));
    }

    #[Test]
    public function normalize_screen_id_strips_every_digit_not_just_a_trailing_suffix(): void
    {
        $used = [];
        $this->assertSame('SCREEN_STEP', MetaFlowIdentifier::normalizeScreenId('SCREEN_STEP_1', $used), 'The exact real-world SCREEN_STEP_1_1-shaped violation must lose its digit entirely, not just its suffix.');

        $used = [];
        $this->assertSame('STEP', MetaFlowIdentifier::normalizeScreenId('step2', $used), 'An embedded digit (not just a suffix) must also be stripped for screen ids.');
    }

    #[Test]
    public function normalize_screen_id_preserves_an_already_valid_id_and_uppercases_consistently(): void
    {
        $used = [];
        $this->assertSame('CONTACT_US', MetaFlowIdentifier::normalizeScreenId('contact_us', $used), 'Already-valid (letters/underscores only) — case-normalized to this compiler\'s established uppercase convention.');
    }

    #[Test]
    public function normalize_screen_id_disambiguates_collisions_alphabetically_never_numerically(): void
    {
        $used = [];
        $first = MetaFlowIdentifier::normalizeScreenId('SCREEN_step_1', $used);
        $second = MetaFlowIdentifier::normalizeScreenId('SCREEN_step_2', $used);
        $third = MetaFlowIdentifier::normalizeScreenId('SCREEN_step_3', $used);

        $this->assertSame('SCREEN_STEP', $first);
        $this->assertSame('SCREEN_STEP_A', $second);
        $this->assertSame('SCREEN_STEP_B', $third);
        foreach ([$first, $second, $third] as $id) {
            $this->assertDoesNotMatchRegularExpression('/[0-9]/', $id, 'No screen id may contain a digit anywhere, including the disambiguation suffix.');
        }
    }
}
