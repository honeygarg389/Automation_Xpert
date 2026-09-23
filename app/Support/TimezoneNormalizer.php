<?php

namespace App\Support;

use Illuminate\Http\Request;

final class TimezoneNormalizer
{
    public const LEGACY_ALIASES = [
        'Asia/Calcutta' => 'Asia/Kolkata',
        'Asia/Katmandu' => 'Asia/Kathmandu',
        'Asia/Rangoon' => 'Asia/Yangon',
        'Asia/Saigon' => 'Asia/Ho_Chi_Minh',
        'America/Buenos_Aires' => 'America/Argentina/Buenos_Aires',
    ];

    public static function normalize(?string $timezone): ?string
    {
        return $timezone === null ? null : (self::LEGACY_ALIASES[$timezone] ?? $timezone);
    }

    public static function normalizeRequest(Request $request): void
    {
        $input = $request->input();
        self::normalizeArray($input);
        $request->merge($input);
    }

    /** @param array<string, mixed> $input */
    private static function normalizeArray(array &$input): void
    {
        foreach ($input as $key => &$value) {
            if (is_array($value)) {
                self::normalizeArray($value);
            } elseif (in_array($key, ['timezone', 'new_outlet_timezone'], true) && is_string($value)) {
                $value = self::normalize($value);
            }
        }

        unset($value);
    }
}
