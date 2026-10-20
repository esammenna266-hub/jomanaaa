<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Phone numbers are stored in international format: +20... (Egypt), +249... (Sudan).
 */
final class PhoneNumber
{
    private const COUNTRY_CODES = ['EG' => '20', 'SD' => '249'];

    public static function normalize(string $raw, string $defaultCountry = 'EG'): string
    {
        $raw = strtr(trim($raw), [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
        $digits = preg_replace('/\D+/', '', $raw);

        if (str_starts_with($raw, '+')) {
            // already international
        } elseif (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        } elseif (str_starts_with($digits, '0')) {
            $digits = (self::COUNTRY_CODES[$defaultCountry] ?? throw new InvalidArgumentException('Unsupported country.'))
                .substr($digits, 1);
        } elseif (! str_starts_with($digits, '20') && ! str_starts_with($digits, '249')) {
            $digits = (self::COUNTRY_CODES[$defaultCountry] ?? throw new InvalidArgumentException('Unsupported country.'))
                .$digits;
        }

        if (strlen($digits) < 9 || strlen($digits) > 15) {
            throw new InvalidArgumentException('Invalid phone number.');
        }

        return '+'.$digits;
    }
}
