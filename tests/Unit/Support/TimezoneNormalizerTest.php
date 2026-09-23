<?php

namespace Tests\Unit\Support;

use App\Support\TimezoneNormalizer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TimezoneNormalizerTest extends TestCase
{
    #[Test]
    public function it_maps_each_known_legacy_alias_without_validating_other_values(): void
    {
        foreach (TimezoneNormalizer::LEGACY_ALIASES as $legacy => $canonical) {
            $this->assertSame($canonical, TimezoneNormalizer::normalize($legacy));
        }
        $this->assertSame('Asia/Kolkata', TimezoneNormalizer::normalize('Asia/Kolkata'));
        $this->assertSame('Not/A/Timezone', TimezoneNormalizer::normalize('Not/A/Timezone'));
    }
}
