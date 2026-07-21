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
 * Driver for the AWeber API (1.0), authenticated with an OAuth2 access token.
 *
 * AWeber subscriber endpoints are scoped by both account and list, so a
 * "remote list" id encodes both as "{accountId}:{listId}". Pushing a contact
 * creates the subscriber with update_existing so re-pushing the same email is
 * idempotent.
 *
 * @see https://api.aweber.com/
 */
class AWeberProvider implements AutoresponderProvider
{
    private const BASE_URL = 'https://api.aweber.com/1.0';

    /**
     * Cache of listId => (lower-cased custom field name => actual field name).
     *
     * @var array<string, array<string, string>>
     */
    private array $customFieldsByList = [];

    /**
     * @param  array{access_token?: string}  $credentials
     */
    public function __construct(
        private readonly array $credentials,
    ) {}

    public function verify(): bool
    {
        return $this->client()->get('/accounts')->successful();
    }

    public function lists(): array
    {
        $accountId = $this->accountId();

        if ($accountId === null) {
            throw IntegrationException::requestFailed('aweber', 'Unable to resolve the AWeber account.');
        }

        $lists = [];
        $path = "/accounts/{$accountId}/lists?ws.size=100";

        do {
            $response = $this->client()->get($path);

            if ($response->failed()) {
                throw IntegrationException::requestFailed('aweber', $this->errorMessage($response));
            }

            foreach ($response->json('entries', []) as $list) {
                $lists[] = new RemoteList("{$accountId}:{$list['id']}", (string) $list['name']);
            }

            $next = $response->json('next_collection_link');
            $path = $next === null ? null : Str::after($next, self::BASE_URL);
        } while ($path !== null);

        return $lists;
    }

    public function pushContact(string $remoteListId, ContactPayload $contact): ContactSyncResult
    {
        [$accountId, $listId] = array_pad(explode(':', $remoteListId, 2), 2, null);

        if ($accountId === null || $listId === null) {
            return ContactSyncResult::failure('Invalid AWeber list identifier.');
        }

        $body = ['email' => $contact->email, 'update_existing' => true];

        if ($name = $contact->fullName()) {
            $body['name'] = $name;
        }

        $customFields = $this->mapCustomFields($accountId, $listId, $contact);

        if ($customFields !== []) {
            $body['custom_fields'] = $customFields;
        }

        $response = $this->client()->post("/accounts/{$accountId}/lists/{$listId}/subscribers", $body);

        if ($response->successful()) {
            return ContactSyncResult::success($this->subscriberId($response));
        }

        // Without update_existing AWeber rejects duplicates; treat an existing
        // subscriber as already on the list rather than a failure.
        if ($this->isDuplicate($response)) {
            return ContactSyncResult::success();
        }

        return ContactSyncResult::failure($this->errorMessage($response), $response);
    }

    /**
     * Map the payload's phone/country onto the list's EXISTING custom fields,
     * matched by name (case-insensitively). Fields that are not defined on the
     * list are skipped rather than created. The list's custom fields are only
     * fetched when there is a value to map.
     *
     * @return array<string, string>
     */
    private function mapCustomFields(string $accountId, string $listId, ContactPayload $contact): array
    {
        $values = array_filter(
            ['phone' => $contact->phone, 'country' => $contact->country],
            static fn (?string $value): bool => filled($value),
        );

        if ($values === []) {
            return [];
        }

        $available = $this->customFields($accountId, $listId);

        $mapped = [];

        foreach ($values as $field => $value) {
            if ($name = ($available[$field] ?? null)) {
                $mapped[$name] = (string) $value;
            }
        }

        return $mapped;
    }

    /**
     * Fetch the list's custom fields as lower-cased-name => actual-name, cached
     * for the lifetime of the driver.
     *
     * @return array<string, string>
     */
    private function customFields(string $accountId, string $listId): array
    {
        return $this->customFieldsByList[$listId] ??= $this->fetchCustomFields($accountId, $listId);
    }

    /**
     * @return array<string, string>
     */
    private function fetchCustomFields(string $accountId, string $listId): array
    {
        $response = $this->client()->get("/accounts/{$accountId}/lists/{$listId}/custom_fields", ['ws.size' => 100]);

        if ($response->failed()) {
            return [];
        }

        return collect($response->json('entries', []))
            ->mapWithKeys(fn (array $field): array => [
                Str::lower((string) $field['name']) => (string) $field['name'],
            ])
            ->all();
    }

    private function accountId(): ?string
    {
        $response = $this->client()->get('/accounts');

        if ($response->failed()) {
            return null;
        }

        $id = $response->json('entries.0.id');

        return $id === null ? null : (string) $id;
    }

    private function subscriberId(Response $response): ?string
    {
        // A created subscriber is returned via the Location header.
        $location = $response->header('Location');

        if ($location !== '') {
            return Str::afterLast($location, '/');
        }

        $id = $response->json('id');

        return $id === null ? null : (string) $id;
    }

    private function isDuplicate(Response $response): bool
    {
        return str_contains(Str::lower($this->errorMessage($response)), 'already');
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(self::BASE_URL)
            ->withToken($this->credentials['access_token'] ?? '')
            ->acceptJson()
            ->asJson();
    }

    private function errorMessage(Response $response): string
    {
        return $response->json('error.message')
            ?? $response->json('error_description')
            ?? $response->json('message')
            ?? 'HTTP '.$response->status();
    }
}
