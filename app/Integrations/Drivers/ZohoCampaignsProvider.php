<?php

namespace App\Integrations\Drivers;

use App\Integrations\Contracts\AutoresponderProvider;
use App\Integrations\Exceptions\IntegrationException;
use App\Integrations\Support\ContactPayload;
use App\Integrations\Support\ContactSyncResult;
use App\Integrations\Support\RemoteList;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Driver for the Zoho Campaigns API (v1.1), authenticated with a Zoho OAuth2
 * access token. A "remote list" is a mailing list, identified by its listkey.
 *
 * Zoho departs from convention in three ways worth knowing:
 *
 * 1. It answers errors with HTTP 200 and a non-zero "code" in the body, so the
 *    status alone never proves a call succeeded.
 * 2. The auth header is "Zoho-oauthtoken <token>", not "Bearer", even though the
 *    token response reports token_type: Bearer.
 * 3. Contact data is a JSON-encoded string in a single contactinfo parameter
 *    rather than a JSON request body.
 *
 * @see https://www.zoho.com/campaigns/help/developers/
 */
class ZohoCampaignsProvider implements AutoresponderProvider
{
    /**
     * Zoho's own subscribe docs cap usage at 500 calls/minute and warn that
     * exceeding it locks the integration out for 30 minutes, so a paced send is
     * strongly preferable to a burst.
     */
    public const CALLS_PER_MINUTE_LIMIT = 500;

    /**
     * Attributed as the subscription source on every contact Zoho receives.
     */
    private const SOURCE = 'Lead Import';

    /**
     * Zoho reports an existing subscriber as an error rather than a success.
     */
    private const CODE_CONTACT_EXISTS = '2003';

    /**
     * How many lists to request per page. Zoho does not document a maximum.
     */
    private const PAGE_SIZE = 100;

    /**
     * @param  array{access_token?: string, api_domain?: string}  $credentials
     */
    public function __construct(
        private readonly array $credentials,
    ) {}

    public function verify(): bool
    {
        // Zoho has no dedicated credential-check endpoint; the cheapest probe
        // validates the token, the scope and the region in one call.
        return $this->succeeded($this->client()->get('/getmailinglists', [
            'resfmt' => 'JSON',
            'range' => 1,
        ]));
    }

    public function lists(): array
    {
        $lists = [];
        $fromIndex = 1;

        do {
            $response = $this->client()->get('/getmailinglists', [
                'resfmt' => 'JSON',
                'sort' => 'asc',
                'fromindex' => $fromIndex,
                'range' => self::PAGE_SIZE,
            ]);

            if (! $this->succeeded($response)) {
                throw IntegrationException::requestFailed('zoho_campaigns', $this->errorMessage($response));
            }

            $page = $response->json('list_of_details', []);

            foreach ($page as $list) {
                $lists[] = new RemoteList(
                    id: (string) $list['listkey'],
                    name: (string) $list['listname'],
                );
            }

            $fromIndex += self::PAGE_SIZE;
            // Zoho reports no total, so a short page is the only end-of-list signal.
        } while (count($page) === self::PAGE_SIZE);

        return $lists;
    }

    public function pushContact(string $remoteListId, ContactPayload $contact): ContactSyncResult
    {
        $response = $this->client()->asForm()->post('/json/listsubscribe', [
            'resfmt' => 'JSON',
            'listkey' => $remoteListId,
            'contactinfo' => $this->contactInfo($contact),
            'source' => self::SOURCE,
        ]);

        if ($this->succeeded($response)) {
            return ContactSyncResult::success(null);
        }

        // Already subscribed — the destination is in the desired state, which is
        // what every other driver here counts as a success.
        if ($this->code($response) === self::CODE_CONTACT_EXISTS) {
            return ContactSyncResult::success(null);
        }

        return ContactSyncResult::failure($this->errorMessage($response), $response);
    }

    /**
     * Zoho takes the contact as a JSON string in one parameter, keyed by the
     * mailing list's display field names, which are space-separated and
     * case-sensitive.
     *
     * Phone is deliberately omitted: its field name varies per Zoho account and
     * pushing an unrecognised key fails the whole call.
     */
    private function contactInfo(ContactPayload $contact): string
    {
        $info = array_filter([
            'Contact Email' => $contact->email,
            'First Name' => $contact->firstName,
            'Last Name' => $contact->lastName,
        ], fn (?string $value): bool => filled($value));

        return (string) json_encode($info);
    }

    /**
     * Zoho signals failure in the body, not the status line: a non-zero code is
     * an error even on an HTTP 200.
     */
    private function succeeded(Response $response): bool
    {
        return $response->successful() && in_array($this->code($response), ['0', '200'], true);
    }

    private function code(Response $response): string
    {
        return (string) $response->json('code', '');
    }

    private function errorMessage(Response $response): string
    {
        $message = $response->json('message') ?? $response->json('status');

        if (filled($message)) {
            $code = $this->code($response);

            return $code === '' ? (string) $message : (string) $message.' (code '.$code.')';
        }

        return 'HTTP '.$response->status();
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl('https://campaigns.zoho.'.$this->region().'/api/v1.1')
            // Not withToken(): Zoho requires its own scheme rather than Bearer.
            ->withHeaders(['Authorization' => 'Zoho-oauthtoken '.($this->credentials['access_token'] ?? '')])
            ->acceptJson();
    }

    /**
     * The data centre this connection belongs to.
     *
     * Preferred from the api_domain captured when the tokens were issued, so an
     * existing connection keeps working against the region it was made in even
     * if the deployment's configured default later changes. api_domain points at
     * Zoho's generic API host (api.zoho.eu), so only its suffix is usable.
     */
    private function region(): string
    {
        $apiDomain = (string) ($this->credentials['api_domain'] ?? '');

        if (preg_match('/zoho(?:apis)?\.([a-z.]+)$/i', rtrim($apiDomain, '/'), $matches) === 1) {
            return strtolower($matches[1]);
        }

        return (string) config('services.zoho_campaigns.region', 'com');
    }
}
