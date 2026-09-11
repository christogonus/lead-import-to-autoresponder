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
 * Driver for the Sender.net API (v2).
 *
 * Sender organises contacts into groups, so a "remote list" is a group and its
 * id is passed straight through. Creating a subscriber with the group attached
 * does both jobs in one request; an address Sender already knows is added to
 * the group with a second call instead, which is what makes a re-push or a
 * retry land on the existing subscriber rather than failing as a duplicate.
 *
 * @see https://api.sender.net/
 */
class SenderNetProvider implements AutoresponderProvider
{
    private const BASE_URL = 'https://api.sender.net/v2';

    /**
     * Groups per page when listing. Sender pages everything and caps nothing
     * documented, so this is simply large enough to keep the walk short.
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
        // The groups endpoint rather than the account: it is the one the rest of
        // the integration depends on, so a token without access to it is no use
        // here even if it authenticates.
        return $this->client()->get('/groups', ['limit' => 1])->successful();
    }

    public function lists(): array
    {
        $groups = [];
        $page = 1;

        do {
            $response = $this->client()->get('/groups', [
                'page' => $page,
                'limit' => self::PAGE_SIZE,
            ]);

            if ($response->failed()) {
                throw IntegrationException::requestFailed('sender_net', $this->errorMessage($response));
            }

            foreach ($response->json('data', []) as $group) {
                $groups[] = new RemoteList(
                    id: (string) $group['id'],
                    name: (string) ($group['title'] ?? $group['id']),
                );
            }

            $lastPage = (int) $response->json('meta.last_page', $page);
        } while ($page++ < $lastPage);

        return $groups;
    }

    public function pushContact(string $remoteListId, ContactPayload $contact): ContactSyncResult
    {
        $response = $this->client()->post('/subscribers', [
            'email' => $contact->email,
            ...$this->optionalFields($contact),
            'groups' => [$remoteListId],
        ]);

        if ($response->successful()) {
            return ContactSyncResult::success($this->subscriberId($response));
        }

        // Sender rejects an address it already holds, so the create above cannot
        // be the whole story: put the existing subscriber in the group instead.
        // Anything else — an address Sender will not accept at all — comes back
        // as the original rejection, which says far more than "not added" does.
        if (! $this->addToGroup($remoteListId, $contact->email)) {
            return ContactSyncResult::failure($this->errorMessage($response), $response);
        }

        return ContactSyncResult::success($this->findSubscriberId($contact->email));
    }

    /**
     * Add an address Sender already knows to a group.
     *
     * Sender answers with the addresses it added and the ones it has never seen,
     * so a 200 alone does not mean this worked — the address has to come back in
     * the added list.
     */
    private function addToGroup(string $groupId, string $email): bool
    {
        $response = $this->client()->post("/subscribers/groups/{$groupId}", [
            'subscribers' => [$email],
        ]);

        if ($response->failed()) {
            return false;
        }

        $added = collect($response->json('message.subscribers_added_to_group', []))
            ->map(fn ($address): string => Str::lower((string) $address));

        return $added->contains(Str::lower($email));
    }

    /**
     * The subscriber id for an address, or null when it cannot be read.
     *
     * Only used on the path where the subscriber already existed, so the id was
     * never in a response we saw. It is a nicety — the push has already
     * succeeded — so a failed lookup is left as null rather than failing it.
     */
    private function findSubscriberId(string $email): ?string
    {
        $response = $this->client()->get('/subscribers/'.rawurlencode($email));

        return $response->successful() ? $this->subscriberId($response) : null;
    }

    private function subscriberId(Response $response): ?string
    {
        $id = $response->json('data.id');

        return $id === null ? null : (string) $id;
    }

    /**
     * The optional subscriber fields, omitting whatever the contact does not
     * carry so Sender is never sent an empty name.
     *
     * Phone is only sent when it is in the international form Sender demands
     * ("+370" or "00370"): a local-format number fails the whole request, and
     * losing the number is a far better outcome than losing the subscriber.
     * Country is not sent at all — Sender has no standard field for it.
     *
     * @return array<string, string>
     */
    private function optionalFields(ContactPayload $contact): array
    {
        $phone = Str::of((string) $contact->phone)->replaceMatches('/[\s()-]+/', '')->value();

        return collect([
            'firstname' => $contact->firstName,
            'lastname' => $contact->lastName,
            'phone' => Str::startsWith($phone, ['+', '00']) ? $phone : null,
        ])
            ->filter(fn (?string $value): bool => filled($value))
            ->all();
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
        $message = $response->json('message');

        // A validation failure puts the useful part in errors, keyed by field,
        // and leaves message generic.
        $details = collect($response->json('errors', []))
            ->flatten()
            ->filter(fn ($detail): bool => is_string($detail))
            ->implode(' ');

        if ($details !== '') {
            return $details;
        }

        return is_string($message) && $message !== '' ? $message : 'HTTP '.$response->status();
    }
}
