<?php

declare(strict_types=1);

namespace PayBridge\Plaid\Tests\Unit;

use PayBridge\Plaid\Support\Decimal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DecimalTest extends TestCase
{
    public function test_normalise_rejects_non_decimal_input(): void
    {
        foreach (array('', '-1', '1e3', '0x10', '1,00', ' ', '.5', '5.', 'NaN', '1.2.3') as $value) {
            self::assertSame('', Decimal::normalise($value), $value);
        }
    }

    public function test_normalise_is_canonical(): void
    {
        self::assertSame('11.11', Decimal::normalise('11.1100'));
        self::assertSame('', Decimal::normalise('011.11'));
        self::assertSame('0', Decimal::normalise('0.000'));
        self::assertSame('100', Decimal::normalise('100.00'));
    }

    /** @return iterable<string, array{string, string, int}> */
    public static function comparisons(): iterable
    {
        yield 'equal with padding' => array('11.1', '11.10', 0);
        yield 'fraction ordering' => array('0.1', '0.09', 1);
        yield 'whole length' => array('100', '99.99', 1);
        yield 'float trap' => array('0.3', '0.30000000000000004', -1);
        yield 'large unsigned 64-bit' => array('18446744073709551615', '18446744073709551614', 1);
    }

    #[DataProvider('comparisons')]
    public function test_compare(string $left, string $right, int $expected): void
    {
        self::assertSame($expected, Decimal::compare($left, $right));
    }

    public function test_compare_rejects_invalid(): void
    {
        self::assertNull(Decimal::compare('abc', '1'));
        self::assertFalse(Decimal::equal('', ''));
    }

    public function test_scale(): void
    {
        self::assertSame(2, Decimal::scale('11.11'));
        self::assertSame(0, Decimal::scale('11.00'));
        self::assertSame(3, Decimal::scale('1.005'));
    }
}
