<?php

declare(strict_types=1);

namespace Buckmerce\Plaid\Tests\Unit;

use Buckmerce\Plaid\Payment\PaymentSnapshot;
use Buckmerce\Plaid\Plaid\Client\PlaidResponse;
use Buckmerce\Plaid\Plaid\DTO\TransferIntent;
use Buckmerce\Plaid\Plaid\Exception\PlaidMalformedResponseException;
use Buckmerce\Plaid\Plaid\Link\LinkTokenService;
use Buckmerce\Plaid\Plaid\Transfer\TransferEventService;
use Buckmerce\Plaid\Plaid\Transfer\TransferService;
use Buckmerce\Plaid\Plaid\TransferIntent\TransferIntentRequest;
use Buckmerce\Plaid\Plaid\TransferIntent\TransferIntentService;
use Buckmerce\Plaid\Tests\Support\FakePlaidClient;
use PHPUnit\Framework\TestCase;

final class PlaidServicesTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function fixture(string $name): array
    {
        $data = json_decode((string) file_get_contents(dirname(__DIR__) . '/fixtures/plaid/' . $name), true, 512, JSON_BIGINT_AS_STRING);
        self::assertIsArray($data);
        return $data;
    }

    public function test_intent_request_is_derived_from_server_side_snapshot(): void
    {
        $snapshot = PaymentSnapshot::create(1001, '11.11', 'USD', 'sandbox');
        $request = TransferIntentRequest::build($snapshot, 'PAYMENT', array('legal_name' => 'Anne Charleston', 'email_address' => 'anne@example.com'), 'same-day-ach', '');
        self::assertSame('PAYMENT', $request['mode']);
        self::assertSame('11.11', $request['amount']);
        self::assertSame('USD', $request['iso_currency_code']);
        self::assertSame('PAYMENT', $request['description'], 'A stable purpose word, not an order number (Plaid description recommendations).');
        self::assertSame('web', $request['ach_class'], 'Transfer UI debits are always WEB.');
        self::assertSame('same-day-ach', $request['network']);
        self::assertArrayNotHasKey('funding_account_id', $request, 'Plaid Ledger accounts reject funding_account_id.');
        self::assertSame(array('bmfp_order_id' => '1001', 'bmfp_attempt_id' => $snapshot->attempt_id, 'bmfp_environment' => 'sandbox'), $request['metadata']);
        foreach ($request['metadata'] as $key => $value) {
            self::assertLessThanOrEqual(40, strlen($key));
            self::assertMatchesRegularExpression('/^[\x20-\x7E]*$/', $value);
        }
        $legacy = TransferIntentRequest::build($snapshot, 'STORE', array('legal_name' => 'A B'), 'ach', 'fa-1');
        self::assertSame('fa-1', $legacy['funding_account_id']);
        self::assertSame('web', $legacy['ach_class']);
        $marked = TransferIntentRequest::build($snapshot, 'PAYMENT', array('legal_name' => 'A B'), 'ach', '', 'abcdef0123456789');
        self::assertSame('abcdef0123456789', $marked['metadata']['bmfp_site'], 'Site marker distinguishes stores sharing a Plaid account.');
        self::assertCount(4, $marked['metadata']);
    }

    public function test_description_is_the_normalized_statement_descriptor_within_ach_limits(): void
    {
        self::assertSame('PAYMENT', TransferIntentRequest::description('payment'));
        self::assertSame('MY STORE', TransferIntentRequest::description('My Store'));
        self::assertSame('PAYMENT', TransferIntentRequest::description('ÜÖ'), 'Nothing usable left → the documented default.');
        self::assertSame('PAYMENT', TransferIntentRequest::description(''));
        foreach (array('1', str_repeat('9', 40), 'Ñandú-7', '<script>x</script>') as $input) {
            $description = TransferIntentRequest::description($input);
            self::assertGreaterThanOrEqual(1, strlen($description));
            self::assertLessThanOrEqual(10, strlen($description), 'ACH descriptions show at most 10 characters.');
            self::assertMatchesRegularExpression('/^[A-Z0-9 ]+$/', $description);
        }
    }

    public function test_legal_name_is_never_invented(): void
    {
        self::assertSame('Anne Charleston', TransferIntentRequest::legal_name('Anne', 'Charleston'));
        self::assertSame('Anne Marie Charleston', TransferIntentRequest::legal_name('  Anne   Marie ', " Charleston\t"));
        self::assertSame('', TransferIntentRequest::legal_name('', 'Charleston'), 'Missing first name fails.');
        self::assertSame('', TransferIntentRequest::legal_name('Anne', ''), 'Missing last name fails.');
        self::assertSame('', TransferIntentRequest::legal_name('', ''), 'Empty name fails; never "Customer".');
        self::assertSame('', TransferIntentRequest::legal_name('  ', "\t"));
        self::assertSame('', TransferIntentRequest::legal_name('<>', '<>'), 'Markup-only input is not a name.');
        self::assertSame('José Nuñez', TransferIntentRequest::legal_name('José', 'Nuñez'));
        self::assertSame(100, mb_strlen(TransferIntentRequest::legal_name(str_repeat('a', 80), str_repeat('b', 80))));
    }

    public function test_intent_get_parses_the_documented_schema(): void
    {
        $client = (new FakePlaidClient())->on('/transfer/intent/get', static fn (): PlaidResponse => new PlaidResponse(self::fixture('transfer_intent_pending.json'), '81a5539f6f21544'));
        $intent = (new TransferIntentService($client))->get('538932c1-9aa1-bcdb-7f86-b359ae3ca7b4');
        self::assertSame(TransferIntent::PENDING, $intent->status);
        self::assertSame('11.11', $intent->amount);
        self::assertSame('', $intent->transfer_id);
        self::assertSame('1001', $intent->metadata['bmfp_order_id']);
        self::assertSame(array('transfer_intent_id' => '538932c1-9aa1-bcdb-7f86-b359ae3ca7b4'), $client->calls[0]['body']);
    }

    public function test_succeeded_intent_without_transfer_id_is_malformed(): void
    {
        $data = self::fixture('transfer_intent_pending.json');
        $data['transfer_intent']['status'] = 'SUCCEEDED';
        $client = (new FakePlaidClient())->on('/transfer/intent/get', static fn (): PlaidResponse => new PlaidResponse($data, 'r'));
        $this->expectException(PlaidMalformedResponseException::class);
        (new TransferIntentService($client))->get('x');
    }

    public function test_unknown_intent_status_is_malformed(): void
    {
        $data = self::fixture('transfer_intent_pending.json');
        $data['transfer_intent']['status'] = 'COMPLETED';
        $client = (new FakePlaidClient())->on('/transfer/intent/get', static fn (): PlaidResponse => new PlaidResponse($data, 'r'));
        $this->expectException(PlaidMalformedResponseException::class);
        (new TransferIntentService($client))->get('x');
    }

    public function test_link_token_is_bound_to_the_intent_and_never_carries_amount(): void
    {
        $client = (new FakePlaidClient())->on('/link/token/create', static fn (): PlaidResponse => new PlaidResponse(array('link_token' => 'link-sandbox-abc', 'expiration' => '2026-09-29T14:12:52Z', 'request_id' => 'r1'), 'r1'));
        $token = (new LinkTokenService($client))->create_for_intent('ti-1', 'bmfp-user', 'My Store', 'en', 'pay_one_account');
        self::assertSame('link-sandbox-abc', $token->token);
        $body = $client->calls[0]['body'];
        self::assertSame(array('transfer'), $body['products']);
        self::assertSame(array('intent_id' => 'ti-1'), $body['transfer']);
        self::assertSame(array('US'), $body['country_codes']);
        self::assertSame('pay_one_account', $body['link_customization_name']);
        self::assertArrayNotHasKey('amount', $body);
        self::assertSame('en', LinkTokenService::language_from_locale('de_DE'));
        self::assertSame('es', LinkTokenService::language_from_locale('es_MX'));
    }

    public function test_link_token_response_without_token_is_malformed(): void
    {
        $client = (new FakePlaidClient())->on('/link/token/create', static fn (): PlaidResponse => new PlaidResponse(array('expiration' => 'x'), 'r'));
        $this->expectException(PlaidMalformedResponseException::class);
        (new LinkTokenService($client))->create_for_intent('ti', 'u', 'n', 'en');
    }

    public function test_event_sync_parses_events_and_keeps_unsigned_64_bit_ids(): void
    {
        $client = (new FakePlaidClient())->on('/transfer/event/sync', static fn (): PlaidResponse => new PlaidResponse(self::fixture('transfer_event_sync.json'), 'mdq'));
        $page = (new TransferEventService($client))->sync('0');
        self::assertCount(4, $page->events);
        self::assertFalse($page->has_more);
        self::assertSame('1', $page->events[0]->event_id);
        self::assertTrue($page->events[0]->is_lifecycle_event());
        self::assertSame('R01', $page->events[2]->return_code());
        self::assertSame('18446744073709551615', $page->events[3]->event_id);
        self::assertFalse($page->events[3]->is_lifecycle_event(), 'Sweep events never drive order state.');
        self::assertSame(array('after_id' => 0, 'count' => 100), $client->calls[0]['body']);
    }

    public function test_event_sync_rejects_invalid_cursor_and_bad_event_ids(): void
    {
        $client = (new FakePlaidClient())->on('/transfer/event/sync', static fn (): PlaidResponse => new PlaidResponse(array('transfer_events' => array(array('event_id' => -1, 'event_type' => 'pending')), 'has_more' => false), 'r'));
        try {
            (new TransferEventService($client))->sync('-5');
            self::fail('Expected invalid cursor rejection.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame(array(), $client->calls);
        }
        $this->expectException(PlaidMalformedResponseException::class);
        (new TransferEventService($client))->sync('0');
    }

    public function test_transfer_get_validates_status_and_amount(): void
    {
        $transfer = array('id' => 't-1', 'status' => 'returned', 'type' => 'debit', 'amount' => '33.33', 'iso_currency_code' => 'USD', 'failure_reason' => array('failure_code' => 'R01', 'ach_return_code' => 'R01', 'description' => 'Insufficient funds'), 'metadata' => array('bmfp_order_id' => '7'));
        $client = (new FakePlaidClient())->on('/transfer/get', static fn (): PlaidResponse => new PlaidResponse(array('transfer' => $transfer, 'request_id' => 'r'), 'r'));
        $result = (new TransferService($client))->get('t-1');
        self::assertSame('returned', $result->status);
        self::assertSame('R01', $result->failure_code);
        self::assertSame('7', $result->metadata['bmfp_order_id']);

        $transfer['status'] = 'paid';
        $bad = (new FakePlaidClient())->on('/transfer/get', static fn (): PlaidResponse => new PlaidResponse(array('transfer' => $transfer), 'r'));
        $this->expectException(PlaidMalformedResponseException::class);
        (new TransferService($bad))->get('t-1');
    }
}
