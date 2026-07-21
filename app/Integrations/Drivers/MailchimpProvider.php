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
use Illuminate\Support\Str;

/**
 * Driver for the Mailchimp Marketing API (v3).
 *
 * A "remote list" is a Mailchimp audience. Contacts are upserted by their
 * subscriber hash so that re-pushing the same email is idempotent (retries and
 * dedup never create duplicates or flip an existing subscriber's status).
 *
 * @see https://mailchimp.com/developer/marketing/api/
 */
class MailchimpProvider implements AutoresponderProvider
{
    /**
     * @param  array{api_key?: string}  $credentials
     */
    public function __construct(
        private readonly array $credentials,
    ) {}

    public function verify(): bool
    {
        return $this->client()->get('/ping')->successful();
    }

    public function lists(): array
    {
        $response = $this->client()->get('/lists', [
            'count' => 1000,
            'fields' => 'lists.id,lists.name',
        ]);

        if ($response->failed()) {
            throw IntegrationException::requestFailed('mailchimp', $this->errorMessage($response));
        }

        return collect($response->json('lists', []))
            ->map(fn (array $list): RemoteList => new RemoteList(
                id: (string) $list['id'],
                name: (string) $list['name'],
            ))
            ->all();
    }

    public function pushContact(string $remoteListId, ContactPayload $contact): ContactSyncResult
    {
        $subscriberHash = md5(Str::lower($contact->email));

        $response = $this->client()->put("/lists/{$remoteListId}/members/{$subscriberHash}", [
            'email_address' => $contact->email,
            'status_if_new' => 'subscribed',
            'merge_fields' => $this->mergeFields($contact),
        ]);

        if ($response->successful()) {
            return ContactSyncResult::success($response->json('id'));
        }

        return ContactSyncResult::failure($this->errorMessage($response), $response);
    }

    /**
     * Map the payload onto Mailchimp's default merge fields, omitting blanks so
     * we never overwrite an existing value with an empty string.
     *
     * @return array<string, string>
     */
    private function mergeFields(ContactPayload $contact): array
    {
        $mapping = [
            'FNAME' => $contact->firstName,
            'LNAME' => $contact->lastName,
            'PHONE' => $contact->phone,
        ];

        return collect($mapping)
            ->filter(fn (?string $value): bool => filled($value))
            ->map(fn (string $value): string => $value)
            ->all();
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl())
            ->withBasicAuth('key', $this->credentials['api_key'] ?? '')
            ->acceptJson()
            ->asJson();
    }

    /**
     * Mailchimp API keys carry their datacenter as a suffix (e.g. "...-us21"),
     * which determines the host the request must be sent to.
     */
    private function baseUrl(): string
    {
        $dataCenter = Str::afterLast($this->credentials['api_key'] ?? '', '-');

        return "https://{$dataCenter}.api.mailchimp.com/3.0";
    }

    private function errorMessage(Response $response): string
    {
        return $response->json('detail')
            ?? $response->json('title')
            ?? 'HTTP '.$response->status();
    }
}
