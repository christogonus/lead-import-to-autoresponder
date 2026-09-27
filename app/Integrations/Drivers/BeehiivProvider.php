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
 * Driver for the beehiiv API (v2).
 *
 * beehiiv has no lists: a subscriber belongs to a publication, so a "remote
 * list" is a publication. Authentication is a static API key sent as a Bearer
 * token, so there is nothing to refresh.
 *
 * A subscriber is created with reactivate_existing left off, so an address that
 * has unsubscribed from the publication stays unsubscribed.
 *
 * @see https://developers.beehiiv.com/api-reference/subscriptions/create
 */
class BeehiivProvider implements AutoresponderProvider
{
    private const BASE_URL = 'https://api.beehiiv.com/v2';

    /**
     * beehiiv's documented ceiling, counted per organization.
     */
    public const CALLS_PER_MINUTE_LIMIT = 180;

    /**
     * Publications per page — the most beehiiv returns in one call.
     */
    private const PAGE_SIZE = 100;

    /**
     * @param  array{api_key?: string}  $credentials
     */
    public function __construct(
        private readonly array $credentials,
    ) {}

    public function verify(): bool
    {
        return $this->client()->get('/publications', ['limit' => 1])->successful();
    }

    public function lists(): array
    {
        $lists = [];
        $page = 1;

        do {
            $response = $this->client()->get('/publications', [
                'limit' => self::PAGE_SIZE,
                'page' => $page,
            ]);

            if ($response->failed()) {
                throw IntegrationException::requestFailed('beehiiv', $this->errorMessage($response));
            }

            foreach ($response->json('data') ?? [] as $publication) {
                $lists[] = new RemoteList(
                    id: (string) $publication['id'],
                    name: (string) ($publication['name'] ?? $publication['id']),
                );
            }

            $page++;
        } while ($page <= (int) $response->json('total_pages', 1));

        return $lists;
    }

    public function pushContact(string $remoteListId, ContactPayload $contact): ContactSyncResult
    {
        $subscription = [
            'email' => $contact->email,
            'reactivate_existing' => false,
            'send_welcome_email' => true,
        ];

        if ($customFields = $this->customFields($contact)) {
            $subscription['custom_fields'] = $customFields;
        }

        $response = $this->client()->post("/publications/{$remoteListId}/subscriptions", $subscription);

        if ($response->successful()) {
            return ContactSyncResult::success($response->json('data.id'));
        }

        return ContactSyncResult::failure($this->errorMessage($response), $response);
    }

    /**
     * The contact's details as beehiiv custom fields, omitting whatever it does
     * not carry. beehiiv has no standard name or phone fields and silently
     * discards any custom field the publication has not defined, so these only
     * land where the publication has fields by these names.
     *
     * @return array<int, array{name: string, value: string}>
     */
    private function customFields(ContactPayload $contact): array
    {
        return collect([
            'First Name' => $contact->hasRealFirstName() ? $contact->firstName : null,
            'Last Name' => $contact->lastName,
            'Phone' => $contact->phone,
            'Country' => $contact->country,
        ])
            ->filter(fn (?string $value): bool => filled($value))
            ->map(fn (string $value, string $name): array => ['name' => $name, 'value' => $value])
            ->values()
            ->all();
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(self::BASE_URL)
            ->withToken($this->credentials['api_key'] ?? '')
            ->acceptJson()
            ->asJson();
    }

    /**
     * beehiiv reports errors as a list of {message, code} objects, with a
     * top-level message on some responses.
     */
    private function errorMessage(Response $response): string
    {
        foreach (['errors.0.message', 'message', 'error'] as $key) {
            $message = $response->json($key);

            if (is_string($message) && $message !== '') {
                return $message;
            }
        }

        return 'HTTP '.$response->status();
    }
}
