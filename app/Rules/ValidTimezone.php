<?php

namespace App\Rules;

use App\Support\TimezoneNormalizer;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class ValidTimezone implements ValidationRule
{
    /** @var array<string, true>|null */
    private static ?array $identifiers = null;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! isset(self::identifiers()[TimezoneNormalizer::normalize($value)])) {
            $fail('The :attribute must be a valid timezone.');
        }
    }

    /** @return array<string, true> */
    private static function identifiers(): array
    {
        return self::$identifiers ??= array_fill_keys(\DateTimeZone::listIdentifiers(\DateTimeZone::ALL), true);
    }
}
