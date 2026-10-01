<?php

declare(strict_types=1);

namespace Buckmerce\Plaid\Tests\Unit;

use Buckmerce\Plaid\Logging\Redactor;
use PHPUnit\Framework\TestCase;

final class RedactorTest extends TestCase
{
    public function test_redacts_nested_sensitive_keys_in_any_case(): void
    {
        $input = array(
            'order_id' => 12,
            'request_id' => 'req-1',
            'PLAID-SECRET' => 'top',
            'headers' => array('Authorization' => 'Bearer x', 'Plaid-Verification' => 'jwt', 'Cookie' => 'c'),
            'nested' => array('deeper' => array('access_token' => 'access-sandbox-1', 'Account_Number' => '1111', 'routing_number' => '0110')),
            'user' => (object) array('legal_name' => 'Jane', 'email_address' => 'j@example.com', 'phone_number' => '1'),
            'link_token' => 'link-sandbox-1',
            'transfer_id' => 't-1',
        );
        $output = Redactor::redact($input);
        self::assertIsArray($output);
        self::assertSame(12, $output['order_id']);
        self::assertSame('req-1', $output['request_id']);
        self::assertSame('t-1', $output['transfer_id']);
        self::assertSame(Redactor::REDACTED, $output['PLAID-SECRET']);
        self::assertSame(Redactor::REDACTED, $output['headers']['Authorization']);
        self::assertSame(Redactor::REDACTED, $output['headers']['Plaid-Verification']);
        self::assertSame(Redactor::REDACTED, $output['headers']['Cookie']);
        self::assertSame(Redactor::REDACTED, $output['nested']['deeper']['access_token']);
        self::assertSame(Redactor::REDACTED, $output['nested']['deeper']['Account_Number']);
        self::assertSame(Redactor::REDACTED, $output['nested']['deeper']['routing_number']);
        self::assertSame(Redactor::REDACTED, $output['user']['legal_name']);
        self::assertSame(Redactor::REDACTED, $output['user']['email_address']);
        self::assertSame(Redactor::REDACTED, $output['link_token']);
        $encoded = (string) json_encode($output);
        foreach (array('top', 'Bearer', 'access-sandbox-1', 'link-sandbox-1', 'j@example.com', 'Jane') as $secret) {
            self::assertStringNotContainsString($secret, $encoded);
        }
    }

    public function test_depth_is_bounded(): void
    {
        $value = array('a' => 'x');
        for ($i = 0; $i < 20; ++$i) {
            $value = array('level' => $value);
        }
        self::assertStringContainsString(Redactor::REDACTED, (string) json_encode(Redactor::redact($value)));
    }
}
