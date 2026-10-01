<?php

declare(strict_types=1);

namespace Buckmerce\Plaid\Plaid\Link;

use Buckmerce\Plaid\Plaid\Client\PlaidClientInterface;
use Buckmerce\Plaid\Plaid\DTO\LinkToken;
use Buckmerce\Plaid\Plaid\DTO\Fields;

/**
 * Creates a Link token bound to one stored Transfer Intent (Transfer UI).
 * Every field is derived server-side; the browser cannot influence it.
 */
final class LinkTokenService
{
    public function __construct(private readonly PlaidClientInterface $client)
    {
    }

    public function create_for_intent(string $transfer_intent_id, string $client_user_id, string $client_name, string $language, string $link_customization_name = ''): LinkToken
    {
        $body = array(
            'client_name' => substr('' === trim($client_name) ? 'Store' : trim($client_name), 0, 30),
            'language' => $language,
            'country_codes' => array('US'),
            'products' => array('transfer'),
            'user' => array('client_user_id' => $client_user_id),
            'transfer' => array('intent_id' => $transfer_intent_id),
        );
        if ('' !== $link_customization_name) {
            $body['link_customization_name'] = $link_customization_name;
        }
        $response = $this->client->post('/link/token/create', $body);
        return new LinkToken(
            Fields::required_string($response->data, 'link_token', $response->request_id),
            Fields::required_string($response->data, 'expiration', $response->request_id),
            $response->request_id
        );
    }

    /** Link supports a fixed set of languages; fall back to English. */
    public static function language_from_locale(string $locale): string
    {
        $language = strtolower(substr($locale, 0, 2));
        return in_array($language, array('en', 'es', 'fr'), true) ? $language : 'en';
    }
}
