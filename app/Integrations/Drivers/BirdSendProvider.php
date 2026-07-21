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
 * Driver for the BirdSend API (v1).
 *
 * BirdSend organises contacts with tags, and its write endpoints reference tags
 * by name, so a "remote list" is a tag identified by its name: creating a
 * contact with the tag attached both subscribes the contact and applies the tag
 * in a single request.
 *
 * @see https://developer.birdsend.co/api-reference.html
 */
class BirdSendProvider implements AutoresponderProvider
{
    private const BASE_URL = 'https://api.birdsend.co/v1';

    /**
     * @param  array{api_key?: string}  $credentials
     */
    public function __construct(
        private readonly array $credentials,
    ) {}

    public function verify(): bool
    {
        return $this->client()->get('/tags', ['per_page' => 1])->successful();
    }

    public function lists(): array
    {
        $tags = [];
        $page = 1;

        do {
            $response = $this->client()->get('/tags', [
                'per_page' => 100,
                'page' => $page,
                'order_by' => 'name',
                'sort' => 'asc',
            ]);

            if ($response->failed()) {
                throw IntegrationException::requestFailed('birdsend', $this->errorMessage($response));
            }

            foreach ($response->json('data', []) as $tag) {
                // BirdSend's write endpoints reference tags by name, so the name
                // is the stable identifier the app stores and pushes against.
                $tags[] = new RemoteList((string) $tag['name'], (string) $tag['name']);
            }

            $lastPage = (int) $response->json('meta.last_page', $page);
        } while ($page++ < $lastPage);

        return $tags;
    }

    public function pushContact(string $remoteListId, ContactPayload $contact): ContactSyncResult
    {
        $response = $this->client()->post('/contacts', [
            'email' => $contact->email,
            'fields' => $this->fields($contact),
            'tags' => [$remoteListId],
        ]);

        if ($response->successful()) {
            return ContactSyncResult::success((string) $response->json('contact_id'));
        }

        // The contact may already exist; look it up so we can still apply the tag.
        $contactId = $this->findContactId($contact->email);

        if ($contactId === null) {
            return ContactSyncResult::failure($this->errorMessage($response), $response);
        }

        $tagResponse = $this->client()->post("/contacts/{$contactId}/tags", [
            'tags' => [$remoteListId],
        ]);

        if (! $tagResponse->successful()) {
            return ContactSyncResult::failure($this->errorMessage($tagResponse), $tagResponse);
        }

        return ContactSyncResult::success((string) $contactId);
    }

    /**
     * Map the payload onto BirdSend's default contact fields, omitting blanks.
     *
     * @return array<string, string>
     */
    private function fields(ContactPayload $contact): array
    {
        $mapping = [
            'first_name' => $contact->firstName,
            'last_name' => $contact->lastName,
            'phone' => $contact->phone,
        ];

        return collect($mapping)
            ->filter(fn (?string $value): bool => filled($value))
            ->map(fn (string $value): string => $value)
            ->all();
    }

    private function findContactId(string $email): ?int
    {
        $response = $this->client()->get('/contacts', ['keyword' => $email, 'per_page' => 1]);

        if ($response->failed()) {
            return null;
        }

        $id = $response->json('data.0.contact_id');

        return $id === null ? null : (int) $id;
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(self::BASE_URL)
            ->withToken($this->credentials['api_key'] ?? '')
            ->acceptJson()
            ->asJson();
    }

    private function errorMessage(Response $response): string
    {
        return $response->json('message')
            ?? $response->json('errors.0.message')
            ?? 'HTTP '.$response->status();
    }
}
