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
 * Driver for the SendX REST API (v1).
 *
 * A "remote list" is a SendX list, identified by its prefixed id
 * ("list_OcuxJHdiAvujmwQVJfd3ss"). Creating a contact with the list attached
 * does both jobs in one request. SendX answers an address it already holds with
 * a 409, so that contact is looked up and updated instead — with its current
 * lists sent back alongside the new one, because the docs do not say whether an
 * update replaces a contact's lists or adds to them, and a push must never pull
 * someone off a list they were already on.
 *
 * @see https://docs.sendx.io/api-reference/introduction
 */
class SendXProvider implements AutoresponderProvider
{
    private const BASE_URL = 'https://api.sendx.io/api/v1/rest';

    /**
     * Lists per page when listing — the most SendX will return in one call.
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
        // The lists endpoint rather than anything cheaper: it is the one the
        // rest of the integration depends on.
        return $this->client()->get('/list', ['limit' => 1])->successful();
    }

    public function lists(): array
    {
        $lists = [];
        $offset = 0;

        do {
            $response = $this->client()->get('/list', [
                'offset' => $offset,
                'limit' => self::PAGE_SIZE,
            ]);

            if ($response->failed()) {
                throw IntegrationException::requestFailed('sendx', $this->errorMessage($response));
            }

            $page = $response->json() ?? [];

            foreach ($page as $list) {
                $lists[] = new RemoteList(
                    id: (string) $list['id'],
                    name: (string) ($list['name'] ?? $list['id']),
                );
            }

            $offset += self::PAGE_SIZE;
        } while (count($page) === self::PAGE_SIZE);

        return $lists;
    }

    public function pushContact(string $remoteListId, ContactPayload $contact): ContactSyncResult
    {
        $response = $this->client()->post('/contact', [
            'email' => $contact->email,
            ...$this->optionalFields($contact),
            'lists' => [$remoteListId],
        ]);

        if ($response->successful()) {
            return ContactSyncResult::success($this->contactId($response));
        }

        if ($response->status() !== 409) {
            return ContactSyncResult::failure($this->errorMessage($response), $response);
        }

        $existing = $this->findContact($contact->email);

        if ($existing === null) {
            return ContactSyncResult::failure($this->errorMessage($response), $response);
        }

        $update = $this->client()->put('/contact/'.$existing['id'], [
            'lists' => collect($existing['lists'] ?? [])->push($remoteListId)->unique()->values()->all(),
        ]);

        if ($update->failed()) {
            return ContactSyncResult::failure($this->errorMessage($update), $update);
        }

        return ContactSyncResult::success((string) $existing['id']);
    }

    /**
     * The existing contact for an address, or null when it cannot be found.
     *
     * SendX has no lookup by email, only a partial-match search across names
     * and addresses, so the results are narrowed to the exact address.
     *
     * @return array{id: string, lists?: array<int, string>}|null
     */
    private function findContact(string $email): ?array
    {
        $response = $this->client()->get('/contact', ['search' => $email, 'limit' => self::PAGE_SIZE]);

        if ($response->failed()) {
            return null;
        }

        return collect($response->json() ?? [])
            ->first(fn ($candidate): bool => is_array($candidate)
                && isset($candidate['id'])
                && Str::lower((string) ($candidate['email'] ?? '')) === Str::lower($email));
    }

    private function contactId(Response $response): ?string
    {
        $id = $response->json('id');

        return $id === null ? null : (string) $id;
    }

    /**
     * The optional contact fields, omitting whatever the contact does not carry
     * so SendX is never sent an empty name.
     *
     * Phone and country are not sent: SendX has no standard field for either,
     * and custom fields are addressed by per-account ids.
     *
     * @return array<string, string>
     */
    private function optionalFields(ContactPayload $contact): array
    {
        return collect([
            'firstName' => $contact->firstName,
            'lastName' => $contact->lastName,
        ])
            ->filter(fn (?string $value): bool => filled($value))
            ->all();
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(self::BASE_URL)
            ->withHeader('X-Team-ApiKey', $this->credentials['api_key'] ?? '')
            ->acceptJson()
            ->asJson();
    }

    private function errorMessage(Response $response): string
    {
        $message = $response->json('message');

        return is_string($message) && $message !== '' ? $message : 'HTTP '.$response->status();
    }
}
