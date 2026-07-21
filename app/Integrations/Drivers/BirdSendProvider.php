<?php

namespace App\Integrations\Drivers;

use App\Integrations\Contracts\AutoresponderProvider;
use App\Integrations\Exceptions\IntegrationException;
use App\Integrations\Support\ContactPayload;
use App\Integrations\Support\ContactSyncResult;
use App\Integrations\Support\RemoteList;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Driver for the BirdSend API (v1).
 *
 * BirdSend organises contacts with tags, so a "remote list" is a tag: creating a
 * contact with the tag attached both subscribes the contact and applies the tag
 * in a single request.
 *
 * Its write endpoints reference tags by *name*, but a name is not stable — one
 * rename in BirdSend would orphan every list mapped to it. So a mapping stores
 * the tag's id and the current name is looked up at push time. Mappings created
 * before this stored the name itself and are still honoured; see tagName().
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
        return collect($this->fetchTags())
            ->map(fn (array $tag): RemoteList => new RemoteList(
                // Fall back to the name when the API omits an id, so a mapping
                // can still be made rather than the tag being unselectable.
                id: (string) ($tag['tag_id'] ?? $tag['name']),
                name: (string) $tag['name'],
            ))
            ->all();
    }

    public function pushContact(string $remoteListId, ContactPayload $contact): ContactSyncResult
    {
        try {
            $tagName = $this->tagName($remoteListId);
        } catch (IntegrationException $e) {
            return ContactSyncResult::failure($e->getMessage());
        }

        if ($tagName === null) {
            // Pushing an unresolvable id would silently create a tag named after
            // it, so fail loudly and leave the mapping to be repointed.
            return ContactSyncResult::failure('That tag no longer exists in BirdSend.');
        }

        $response = $this->client()->post('/contacts', [
            'email' => $contact->email,
            'fields' => $this->fields($contact),
            'tags' => [$tagName],
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
            'tags' => [$tagName],
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

    /**
     * Resolve a stored mapping to the tag name BirdSend's write API expects.
     *
     * A non-numeric value is a mapping made before ids were stored: it is the
     * tag name already. (A tag named e.g. "2024" is indistinguishable from an
     * id here — remap it if that ever bites.)
     */
    private function tagName(string $remoteListId): ?string
    {
        if (! ctype_digit($remoteListId)) {
            return $remoteListId;
        }

        return $this->tagNamesById()[$remoteListId] ?? null;
    }

    /**
     * The tag id => name map, cached briefly because a paced send resolves the
     * same tag once per contact and the names rarely move.
     *
     * @return array<string, string>
     */
    private function tagNamesById(): array
    {
        $key = 'birdsend:tag-names:'.hash('sha256', $this->credentials['api_key'] ?? '');

        return Cache::remember($key, now()->addMinutes(5), fn (): array => collect($this->fetchTags())
            ->filter(fn (array $tag): bool => isset($tag['tag_id']))
            ->mapWithKeys(fn (array $tag): array => [(string) $tag['tag_id'] => (string) $tag['name']])
            ->all());
    }

    /**
     * Every tag, following BirdSend's pagination.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchTags(): array
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
                $tags[] = $tag;
            }

            $lastPage = (int) $response->json('meta.last_page', $page);
        } while ($page++ < $lastPage);

        return $tags;
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
