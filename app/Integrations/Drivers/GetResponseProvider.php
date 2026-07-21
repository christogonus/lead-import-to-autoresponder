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
 * Driver for the GetResponse REST API (v3).
 *
 * @see https://apidocs.getresponse.com/v3
 */
class GetResponseProvider implements AutoresponderProvider
{
    private const BASE_URL = 'https://api.getresponse.com/v3';

    /**
     * Cache of lower-cased custom field name => custom field id.
     *
     * @var array<string, string>|null
     */
    private ?array $customFields = null;

    /**
     * @param  array{api_key?: string}  $credentials
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
        $response = $this->client()->get('/campaigns', [
            'perPage' => 1000,
            'sort' => ['name' => 'asc'],
        ]);

        if ($response->failed()) {
            throw IntegrationException::requestFailed('getresponse', $this->errorMessage($response));
        }

        return collect($response->json())
            ->map(fn (array $campaign): RemoteList => new RemoteList(
                id: (string) $campaign['campaignId'],
                name: (string) $campaign['name'],
            ))
            ->all();
    }

    public function pushContact(string $remoteListId, ContactPayload $contact): ContactSyncResult
    {
        $body = [
            'email' => $contact->email,
            'campaign' => ['campaignId' => $remoteListId],
        ];

        if ($name = $contact->fullName()) {
            $body['name'] = $name;
        }

        $customFieldValues = $this->buildCustomFieldValues($contact);

        if ($customFieldValues !== []) {
            $body['customFieldValues'] = $customFieldValues;
        }

        $response = $this->client()->post('/contacts', $body);

        if ($response->successful()) {
            return ContactSyncResult::success($response->json('contactId'));
        }

        return ContactSyncResult::failure($this->errorMessage($response), $response);
    }

    /**
     * Map the payload's phone/country onto GetResponse custom fields, creating
     * the fields on the account when they don't already exist.
     *
     * @return array<int, array{customFieldId: string, value: array<int, string>}>
     */
    private function buildCustomFieldValues(ContactPayload $contact): array
    {
        $values = [];

        foreach (['phone' => $contact->phone, 'country' => $contact->country] as $field => $value) {
            if (blank($value)) {
                continue;
            }

            if ($customFieldId = $this->resolveCustomFieldId($field)) {
                $values[] = ['customFieldId' => $customFieldId, 'value' => [(string) $value]];
            }
        }

        return $values;
    }

    /**
     * Resolve a custom field id by name, creating the field if it is missing.
     */
    private function resolveCustomFieldId(string $name): ?string
    {
        $this->customFields ??= $this->fetchCustomFields();

        return $this->customFields[$name] ??= $this->createCustomField($name);
    }

    /**
     * @return array<string, string>
     */
    private function fetchCustomFields(): array
    {
        $response = $this->client()->get('/custom-fields', ['perPage' => 1000]);

        if ($response->failed()) {
            return [];
        }

        return collect($response->json())
            ->mapWithKeys(fn (array $field): array => [
                strtolower((string) $field['name']) => (string) $field['customFieldId'],
            ])
            ->all();
    }

    private function createCustomField(string $name): ?string
    {
        $response = $this->client()->post('/custom-fields', [
            'name' => $name,
            'type' => 'text',
            'hidden' => 'false',
            'values' => [],
        ]);

        return $response->successful() ? $response->json('customFieldId') : null;
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(self::BASE_URL)
            ->withHeaders(['X-Auth-Token' => 'api-key '.($this->credentials['api_key'] ?? '')])
            ->acceptJson()
            ->asJson();
    }

    private function errorMessage(Response $response): string
    {
        return $response->json('message')
            ?? $response->json('codeDescription')
            ?? 'HTTP '.$response->status();
    }
}
