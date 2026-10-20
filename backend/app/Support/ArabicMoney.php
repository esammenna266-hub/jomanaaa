<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Writes an amount in Arabic words (تفقيط) for the receipts: "ألف ومائتان وخمسون جنيهاً مصرياً لا غير".
 */
final class ArabicMoney
{
    private const ONES = [
        1 => 'واحد', 2 => 'اثنان', 3 => 'ثلاثة', 4 => 'أربعة', 5 => 'خمسة', 6 => 'ستة', 7 => 'سبعة', 8 => 'ثمانية', 9 => 'تسعة',
        10 => 'عشرة', 11 => 'أحد عشر', 12 => 'اثنا عشر', 13 => 'ثلاثة عشر', 14 => 'أربعة عشر', 15 => 'خمسة عشر',
        16 => 'ستة عشر', 17 => 'سبعة عشر', 18 => 'ثمانية عشر', 19 => 'تسعة عشر',
    ];

    private const TENS = [
        2 => 'عشرون', 3 => 'ثلاثون', 4 => 'أربعون', 5 => 'خمسون', 6 => 'ستون', 7 => 'سبعون', 8 => 'ثمانون', 9 => 'تسعون',
    ];

    private const HUNDREDS = [
        1 => 'مائة', 2 => 'مائتان', 3 => 'ثلاثمائة', 4 => 'أربعمائة', 5 => 'خمسمائة',
        6 => 'ستمائة', 7 => 'سبعمائة', 8 => 'ثمانمائة', 9 => 'تسعمائة',
    ];

    /** scale => [singular, dual, plural] */
    private const SCALES = [
        3 => ['ألف', 'ألفان', 'آلاف'],
        6 => ['مليون', 'مليونان', 'ملايين'],
        9 => ['مليار', 'ملياران', 'مليارات'],
    ];

    /** code => [major: [one, two, few, many], minor: [one, two, few, many]] */
    private const CURRENCIES = [
        'EGP' => [['جنيه مصري', 'جنيهان مصريان', 'جنيهات مصرية', 'جنيهاً مصرياً'], ['قرش', 'قرشان', 'قروش', 'قرشاً']],
        'USD' => [['دولار أمريكي', 'دولاران أمريكيان', 'دولارات أمريكية', 'دولاراً أمريكياً'], ['سنت', 'سنتان', 'سنتات', 'سنتاً']],
        'SDG' => [['جنيه سوداني', 'جنيهان سودانيان', 'جنيهات سودانية', 'جنيهاً سودانياً'], ['قرش', 'قرشان', 'قروش', 'قرشاً']],
    ];

    /**
     * @param  string  $amount  decimal string such as "1250.50"
     */
    public static function words(string $amount, string $currencyCode): string
    {
        if (! isset(self::CURRENCIES[$currencyCode])) {
            throw new InvalidArgumentException("Unsupported currency [{$currencyCode}].");
        }

        if (! preg_match('/^(\d{1,12})(?:\.(\d{1,2}))?$/', $amount, $match)) {
            throw new InvalidArgumentException('Invalid amount.');
        }

        $whole = (int) $match[1];
        $fraction = (int) str_pad($match[2] ?? '0', 2, '0');
        [$major, $minor] = self::CURRENCIES[$currencyCode];

        $parts = [];
        if ($whole > 0 || $fraction === 0) {
            $parts[] = self::counted($whole, $major);
        }
        if ($fraction > 0) {
            $parts[] = self::counted($fraction, $minor);
        }

        return implode(' و', $parts).' لا غير';
    }

    /**
     * @param  array{0: string, 1: string, 2: string, 3: string}  $nouns  one, two, few (3-10), many (11+)
     */
    private static function counted(int $number, array $nouns): string
    {
        if ($number === 0) {
            return 'صفر '.$nouns[0];
        }

        if ($number === 1) {
            return $nouns[0];
        }

        if ($number === 2) {
            return $nouns[1];
        }

        // The noun follows the last part of the number: 3-10 plural, 11-99 accusative, otherwise singular.
        $noun = match (true) {
            $number % 100 >= 11 => $nouns[3],
            $number % 100 >= 3 => $nouns[2],
            default => $nouns[0],
        };

        return self::number($number).' '.$noun;
    }

    private static function number(int $number): string
    {
        $groups = [];
        foreach ([9, 6, 3] as $scale) {
            $divisor = 10 ** $scale;
            $count = intdiv($number, $divisor);
            $number %= $divisor;

            if ($count > 0) {
                $groups[] = self::scaled($count, self::SCALES[$scale]);
            }
        }

        if ($number > 0) {
            $groups[] = self::below1000($number);
        }

        return implode(' و', $groups);
    }

    /**
     * @param  array{0: string, 1: string, 2: string}  $scale
     */
    private static function scaled(int $count, array $scale): string
    {
        return match (true) {
            $count === 1 => $scale[0],
            $count === 2 => $scale[1],
            $count <= 10 => self::below1000($count).' '.$scale[2],
            default => self::below1000($count).' '.$scale[0],
        };
    }

    private static function below1000(int $number): string
    {
        $parts = [];

        if ($number >= 100) {
            $parts[] = self::HUNDREDS[intdiv($number, 100)];
            $number %= 100;
        }

        if ($number >= 20) {
            $unit = $number % 10;
            $parts[] = $unit > 0 ? self::ONES[$unit].' و'.self::TENS[intdiv($number, 10)] : self::TENS[intdiv($number, 10)];
        } elseif ($number > 0) {
            $parts[] = self::ONES[$number];
        }

        return implode(' و', $parts);
    }
}
