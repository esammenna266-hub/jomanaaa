<?php

namespace Tests\Unit\Support;

use App\Support\PhoneNumber;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PhoneNumberTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function validNumbers(): array
    {
        return [
            'egypt local' => ['01012345678', 'EG', '+201012345678'],
            'egypt with spaces' => ['010 1234 5678', 'EG', '+201012345678'],
            'egypt international plus' => ['+201012345678', 'EG', '+201012345678'],
            'egypt double zero' => ['00201012345678', 'EG', '+201012345678'],
            'sudan local' => ['0912345678', 'SD', '+249912345678'],
            'sudan international' => ['+249912345678', 'EG', '+249912345678'],
            'arabic digits' => ['٠١٠١٢٣٤٥٦٧٨', 'EG', '+201012345678'],
        ];
    }

    #[DataProvider('validNumbers')]
    public function test_it_normalizes_to_international_format(string $raw, string $country, string $expected): void
    {
        $this->assertSame($expected, PhoneNumber::normalize($raw, $country));
    }

    public function test_it_rejects_numbers_that_are_too_short(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PhoneNumber::normalize('0123', 'EG');
    }
}
