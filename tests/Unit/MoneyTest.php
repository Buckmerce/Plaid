<?php

declare(strict_types=1);

namespace PayBridge\Plaid\Tests\Unit;

use PayBridge\Plaid\Exception\PaymentException;
use PayBridge\Plaid\Support\Money;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function test_transfer_amount_is_exact_two_decimal_string(): void
    {
        self::assertSame('11.11', Money::transfer_amount('11.11'));
        self::assertSame('10.00', Money::transfer_amount('10'));
        self::assertSame('0.50', Money::transfer_amount('0.5'));
        self::assertSame('33.33', Money::transfer_amount('33.3300'));
    }

    public function test_rejects_zero_negative_and_sub_cent_totals(): void
    {
        foreach (array('0', '0.00', '-1.00', '1.005', 'abc', '') as $total) {
            try {
                Money::transfer_amount($total);
                self::fail('Expected rejection for ' . $total);
            } catch (PaymentException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
    }

    public function test_same_amount_never_uses_float_equality(): void
    {
        self::assertTrue(Money::same_amount('22.2', '22.20'));
        self::assertFalse(Money::same_amount('22.22', '22.21'));
        self::assertFalse(Money::same_amount('0.3', '0.30000000000000004'));
    }
}
