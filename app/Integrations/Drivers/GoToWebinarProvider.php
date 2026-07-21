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
 * Driver for the GoToWebinar API (v2), authenticated with a GoTo OAuth2 access
 * token. GoToWebinar is scoped by an organizer key that arrives in the OAuth
 * token response and is stored alongside the tokens.
 *
 * A "remote list" is a webinar, and pushing a contact registers them as a
 * registrant on that webinar.
 *
 * @see https://developer.goto.com/GoToWebinarV2
 */
class GoToWebinarProvider implements AutoresponderProvider
{
    private const BASE_URL = 'https://api.getgo.com/G2W/rest/v2';

    /**
     * GoToWebinar rejects registrants without a first and last name. Names are
     * derived from the email where possible; this is the last resort for an
     * address with nothing name-like in it (e.g. "12345@example.com").
     */
    private const NAME_FALLBACK = 'Subscriber';

    /**
     * @param  array{access_token?: string, organizer_key?: string}  $credentials
     */
    public function __construct(
        private readonly array $credentials,
    ) {}

    public function verify(): bool
    {
        $organizerKey = $this->organizerKey();

        if ($organizerKey === null) {
            return false;
        }

        return $this->client()->get("/organizers/{$organizerKey}/webinars")->successful();
    }

    public function lists(): array
    {
        $organizerKey = $this->organizerKey();

        if ($organizerKey === null) {
            throw IntegrationException::requestFailed('gotowebinar', 'Missing organizer key for the connection.');
        }

        $response = $this->client()->get("/organizers/{$organizerKey}/webinars");

        if ($response->failed()) {
            throw IntegrationException::requestFailed('gotowebinar', $this->errorMessage($response));
        }

        return collect($this->webinars($response))
            ->map(fn (array $webinar): RemoteList => new RemoteList(
                id: (string) $webinar['webinarKey'],
                name: (string) $webinar['subject'],
            ))
            ->all();
    }

    public function pushContact(string $remoteListId, ContactPayload $contact): ContactSyncResult
    {
        $organizerKey = $this->organizerKey();

        if ($organizerKey === null) {
            return ContactSyncResult::failure('Missing organizer key for the connection.');
        }

        // GoTo requires a real first and last name, so nameless contacts have
        // theirs guessed from the email rather than registering as "there".
        $names = $contact->resolvedNames();

        $response = $this->client()->post(
            "/organizers/{$organizerKey}/webinars/{$remoteListId}/registrants",
            [
                'firstName' => $this->nameOrFallback($names['first']),
                'lastName' => $this->nameOrFallback($names['last']),
                'email' => $contact->email,
            ],
        );

        if ($response->successful()) {
            return ContactSyncResult::success($this->registrantId($response));
        }

        // A 409 means the registrant already exists on the webinar; GoToWebinar
        // returns the existing registrant, so treat it as a success.
        if ($response->status() === 409) {
            return ContactSyncResult::success($this->registrantId($response));
        }

        return ContactSyncResult::failure($this->errorMessage($response), $response);
    }

    /**
     * Normalise the webinar collection, which the API may return as a plain
     * array or wrapped in a HAL "_embedded" envelope.
     *
     * @return array<int, array<string, mixed>>
     */
    private function webinars(Response $response): array
    {
        return $response->json('_embedded.webinars', $response->json() ?: []);
    }

    /**
     * Return the name, or the placeholder when it is blank, so GoToWebinar's
     * required first/last name fields are always satisfied.
     */
    private function nameOrFallback(?string $name): string
    {
        return filled($name) ? $name : self::NAME_FALLBACK;
    }

    private function registrantId(Response $response): ?string
    {
        $id = $response->json('registrantKey');

        return $id === null ? null : (string) $id;
    }

    private function organizerKey(): ?string
    {
        $key = $this->credentials['organizer_key'] ?? null;

        return $key === null ? null : (string) $key;
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
        return $response->json('description')
            ?? $response->json('msg')
            ?? $response->json('error_description')
            ?? 'HTTP '.$response->status();
    }
}
