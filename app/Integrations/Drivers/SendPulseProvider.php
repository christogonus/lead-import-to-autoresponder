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
 * Driver for the SendPulse email service API.
 *
 * A "remote list" is a SendPulse mailing list (an "address book" in the API).
 * Authentication uses a static API key from Settings → API → API keys, sent as
 * a Bearer token, rather than the hour-long client-credentials token, so there
 * is nothing to refresh.
 *
 * Adding an address to a mailing list is an upsert on SendPulse's side — an
 * address already on the list is updated rather than rejected — so a re-push or
 * a retry needs no duplicate handling. The call does not return a subscriber id.
 *
 * @see https://sendpulse.com/integrations/api/bulk-email
 */
class SendPulseProvider implements AutoresponderProvider
{
    private const BASE_URL = 'https://api.sendpulse.com';

    /**
     * SendPulse's hard ceiling, on every plan. Anything faster is refused
     * whatever the per-minute quota says.
     */
    public const CALLS_PER_SECOND_LIMIT = 10;

    /**
     * Mailing lists per page — the most SendPulse returns in one call.
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
        return $this->client()->get('/addressbooks', ['limit' => 1])->successful();
    }

    public function lists(): array
    {
        $lists = [];
        $offset = 0;

        do {
            $response = $this->client()->get('/addressbooks', [
                'limit' => self::PAGE_SIZE,
                'offset' => $offset,
            ]);

            if ($response->failed()) {
                throw IntegrationException::requestFailed('sendpulse', $this->errorMessage($response));
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
        $subscriber = ['email' => $contact->email];

        if ($variables = $this->variables($contact)) {
            $subscriber['variables'] = $variables;
        }

        $response = $this->client()->post("/addressbooks/{$remoteListId}/emails", [
            'emails' => [$subscriber],
        ]);

        // A 200 is not the whole answer: SendPulse reports some refusals in the
        // body with result false.
        if ($response->successful() && $response->json('result') === true) {
            return ContactSyncResult::success();
        }

        return ContactSyncResult::failure($this->errorMessage($response), $response);
    }

    /**
     * The contact's mailing-list variables, omitting whatever it does not carry.
     *
     * "name" and "Phone" are the two variables SendPulse treats as standard.
     * Country has no such variable, so it is not sent.
     *
     * @return array<string, string>
     */
    private function variables(ContactPayload $contact): array
    {
        return collect([
            'name' => $contact->fullName(),
            'Phone' => $contact->phone,
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
        foreach (['message', 'error_description', 'error'] as $key) {
            $message = $response->json($key);

            if (is_string($message) && $message !== '') {
                return $message;
            }
        }

        return 'HTTP '.$response->status();
    }
}
