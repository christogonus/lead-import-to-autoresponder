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
 * Driver for the systeme.io Public API.
 *
 * systeme.io organises contacts with tags rather than lists, so a "remote list"
 * is a tag: pushing a contact creates (or reuses) the contact and assigns the
 * selected tag to it.
 *
 * @see https://developer.systeme.io/reference
 */
class SystemeIoProvider implements AutoresponderProvider
{
    private const BASE_URL = 'https://api.systeme.io';

    /**
     * @param  array{api_key?: string}  $credentials
     */
    public function __construct(
        private readonly array $credentials,
    ) {}

    public function verify(): bool
    {
        return $this->client()->get('/api/tags', ['limit' => 10])->successful();
    }

    public function lists(): array
    {
        $tags = [];
        $startingAfter = null;

        do {
            $query = ['limit' => 100, 'order' => 'asc'];

            if ($startingAfter !== null) {
                $query['startingAfter'] = $startingAfter;
            }

            $response = $this->client()->get('/api/tags', $query);

            if ($response->failed()) {
                throw IntegrationException::requestFailed('systeme_io', $this->errorMessage($response));
            }

            $items = $response->json('items', []);

            foreach ($items as $tag) {
                $tags[] = new RemoteList((string) $tag['id'], (string) $tag['name']);
                $startingAfter = $tag['id'];
            }

            $hasMore = $items !== [] && (bool) $response->json('hasMore', false);
        } while ($hasMore);

        return $tags;
    }

    public function pushContact(string $remoteListId, ContactPayload $contact): ContactSyncResult
    {
        $response = $this->client()->post('/api/contacts', $this->contactBody($contact));

        if ($response->successful()) {
            $contactId = $response->json('id');
        } else {
            // The contact may already exist (e.g. it is on another of your lists);
            // look it up so we can still assign the selected tag.
            $contactId = $this->findContactId($contact->email);

            if ($contactId === null) {
                return ContactSyncResult::failure($this->errorMessage($response), $response);
            }
        }

        $tagResponse = $this->client()->post("/api/contacts/{$contactId}/tags", [
            'tagId' => (int) $remoteListId,
        ]);

        if (! $tagResponse->successful()) {
            return ContactSyncResult::failure($this->errorMessage($tagResponse), $tagResponse);
        }

        return ContactSyncResult::success((string) $contactId);
    }

    /**
     * @return array{email: string, fields?: array<int, array{slug: string, value: string}>}
     */
    private function contactBody(ContactPayload $contact): array
    {
        $fields = [];

        $mapping = [
            'first_name' => $contact->firstName,
            'last_name' => $contact->lastName,
            'phone' => $contact->phone,
            'country' => $contact->country,
        ];

        foreach ($mapping as $slug => $value) {
            if (filled($value)) {
                $fields[] = ['slug' => $slug, 'value' => (string) $value];
            }
        }

        $body = ['email' => $contact->email];

        if ($fields !== []) {
            $body['fields'] = $fields;
        }

        return $body;
    }

    private function findContactId(string $email): ?int
    {
        $response = $this->client()->get('/api/contacts', ['email' => $email, 'limit' => 10]);

        if ($response->failed()) {
            return null;
        }

        $id = $response->json('items.0.id');

        return $id === null ? null : (int) $id;
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(self::BASE_URL)
            ->withHeaders(['X-API-Key' => $this->credentials['api_key'] ?? ''])
            ->acceptJson()
            ->asJson();
    }

    private function errorMessage(Response $response): string
    {
        return $response->json('message')
            ?? $response->json('hydra:description')
            ?? $response->json('detail')
            ?? $response->json('violations.0.message')
            ?? 'HTTP '.$response->status();
    }
}
