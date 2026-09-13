<?php

namespace Tests\Feature\Restaurant;

use App\Modules\Restaurant\Support\IpAllowlistNormalizer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IpAllowlistNormalizerTest extends TestCase
{
    #[Test]
    public function null_input_normalizes_to_null(): void
    {
        $this->assertNull(IpAllowlistNormalizer::normalize(null));
    }

    #[Test]
    public function an_array_is_trimmed_and_deduplicated(): void
    {
        $result = IpAllowlistNormalizer::normalize(['203.0.113.5', ' 203.0.113.5 ', '198.51.100.1', '']);

        $this->assertSame(['203.0.113.5', '198.51.100.1'], $result);
    }

    #[Test]
    public function a_raw_newline_and_comma_separated_string_is_split_trimmed_and_deduplicated(): void
    {
        $result = IpAllowlistNormalizer::normalize("203.0.113.5\n 203.0.113.5 \n\n198.51.100.1,198.51.100.1\n");

        $this->assertSame(['203.0.113.5', '198.51.100.1'], $result);
    }

    #[Test]
    public function an_all_blank_input_normalizes_to_null_not_an_empty_array(): void
    {
        $this->assertNull(IpAllowlistNormalizer::normalize(["\n", '  ', '']));
        $this->assertNull(IpAllowlistNormalizer::normalize("\n\n   \n"));
    }

    /**
     * ⚠️ Normalization deliberately does NOT validate IP shape — it only
     * cleans (trim/dedupe/drop-blank). An entry that is not a real IP is
     * left in the result for the `ip` validation rule to catch and reject
     * with a proper error, not silently dropped here.
     */
    #[Test]
    public function invalid_looking_entries_pass_through_unchanged_for_the_validator_to_reject(): void
    {
        $result = IpAllowlistNormalizer::normalize(['not-an-ip', '203.0.113.5']);

        $this->assertSame(['not-an-ip', '203.0.113.5'], $result);
    }
}
