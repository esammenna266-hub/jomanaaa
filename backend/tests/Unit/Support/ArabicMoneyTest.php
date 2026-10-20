<?php

namespace Tests\Unit\Support;

use App\Support\ArabicMoney;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ArabicMoneyTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function amounts(): array
    {
        return [
            'one pound' => ['1', 'EGP', 'جنيه مصري لا غير'],
            'two pounds' => ['2.00', 'EGP', 'جنيهان مصريان لا غير'],
            'few' => ['5', 'EGP', 'خمسة جنيهات مصرية لا غير'],
            'eleven' => ['11', 'EGP', 'أحد عشر جنيهاً مصرياً لا غير'],
            'tens with units' => ['25', 'SDG', 'خمسة وعشرون جنيهاً سودانياً لا غير'],
            'hundred' => ['100', 'USD', 'مائة دولار أمريكي لا غير'],
            'thousand two fifty' => ['1250', 'EGP', 'ألف ومائتان وخمسون جنيهاً مصرياً لا غير'],
            'two thousand' => ['2000', 'EGP', 'ألفان جنيه مصري لا غير'],
            'with piasters' => ['10.50', 'EGP', 'عشرة جنيهات مصرية وخمسون قرشاً لا غير'],
            'only piasters' => ['0.05', 'EGP', 'خمسة قروش لا غير'],
            'million' => ['1500000', 'USD', 'مليون وخمسمائة ألف دولار أمريكي لا غير'],
            'zero' => ['0', 'EGP', 'صفر جنيه مصري لا غير'],
        ];
    }

    #[DataProvider('amounts')]
    public function test_it_spells_amounts(string $amount, string $currency, string $expected): void
    {
        $this->assertSame($expected, ArabicMoney::words($amount, $currency));
    }

    public function test_it_rejects_bad_input(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ArabicMoney::words('12.345', 'EGP');
    }

    public function test_it_rejects_unknown_currencies(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ArabicMoney::words('10', 'JPY');
    }
}
