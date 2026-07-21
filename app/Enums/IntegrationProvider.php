<?php

namespace App\Enums;

use App\Integrations\Contracts\AutoresponderProvider;
use App\Integrations\Drivers\AWeberProvider;
use App\Integrations\Drivers\BirdSendProvider;
use App\Integrations\Drivers\GetResponseProvider;
use App\Integrations\Drivers\GoToWebinarProvider;
use App\Integrations\Drivers\MailchimpProvider;
use App\Integrations\Drivers\SystemeIoProvider;
use App\Integrations\OAuth\OAuthConfig;

enum IntegrationProvider: string
{
    case GetResponse = 'getresponse';
    case SystemeIo = 'systeme_io';
    case Mailchimp = 'mailchimp';
    case BirdSend = 'birdsend';
    case AWeber = 'aweber';
    case GoToWebinar = 'gotowebinar';

    /**
     * Get the human-readable label for the provider.
     */
    public function label(): string
    {
        return match ($this) {
            self::GetResponse => 'GetResponse',
            self::SystemeIo => 'Systeme.io',
            self::Mailchimp => 'Mailchimp',
            self::BirdSend => 'BirdSend',
            self::AWeber => 'AWeber',
            self::GoToWebinar => 'GoToWebinar',
        };
    }

    /**
     * Get the noun this provider uses for the destination a contact list maps to.
     */
    public function listNoun(): string
    {
        return match ($this) {
            self::GetResponse => 'campaign',
            self::SystemeIo => 'tag',
            self::Mailchimp => 'audience',
            self::BirdSend => 'tag',
            self::AWeber => 'list',
            self::GoToWebinar => 'webinar',
        };
    }

    /**
     * Get the fully-qualified driver class for the provider.
     *
     * @return class-string<AutoresponderProvider>
     */
    public function driver(): string
    {
        return match ($this) {
            self::GetResponse => GetResponseProvider::class,
            self::SystemeIo => SystemeIoProvider::class,
            self::Mailchimp => MailchimpProvider::class,
            self::BirdSend => BirdSendProvider::class,
            self::AWeber => AWeberProvider::class,
            self::GoToWebinar => GoToWebinarProvider::class,
        };
    }

    /**
     * Determine whether the provider connects via an OAuth2 redirect flow rather
     * than manually-entered credentials.
     */
    public function usesOAuth(): bool
    {
        return $this->oauthConfig() !== null;
    }

    /**
     * Get the OAuth2 configuration for the provider, or null when it uses
     * manually-entered credentials.
     */
    public function oauthConfig(): ?OAuthConfig
    {
        return match ($this) {
            self::AWeber => new OAuthConfig(
                authorizeUrl: 'https://auth.aweber.com/oauth2/authorize',
                tokenUrl: 'https://auth.aweber.com/oauth2/token',
                clientId: (string) config('services.aweber.client_id'),
                clientSecret: (string) config('services.aweber.client_secret'),
                scopes: ['account.read', 'list.read', 'subscriber.write'],
            ),
            self::GoToWebinar => new OAuthConfig(
                authorizeUrl: 'https://authentication.logmeininc.com/oauth/authorize',
                tokenUrl: 'https://authentication.logmeininc.com/oauth/token',
                clientId: (string) config('services.gotowebinar.client_id'),
                clientSecret: (string) config('services.gotowebinar.client_secret'),
                // GoTo derives scopes from the OAuth client and uses a confidential
                // client (client secret) rather than PKCE. The organizer key it
                // returns with the tokens is needed for every API call.
                scopes: [],
                usesPkce: false,
                extraTokenFields: ['organizer_key', 'account_key'],
            ),
            default => null,
        };
    }

    /**
     * Get the credential fields required to connect this provider.
     *
     * @return array<int, array{key: string, label: string, type: string, hint?: string}>
     */
    public function credentialFields(): array
    {
        return match ($this) {
            self::GetResponse => [
                [
                    'key' => 'api_key',
                    'label' => 'API key',
                    'type' => 'password',
                    'hint' => 'Found in GetResponse under Menu → Integrations & API → API.',
                ],
            ],
            self::SystemeIo => [
                [
                    'key' => 'api_key',
                    'label' => 'API key',
                    'type' => 'password',
                    'hint' => 'Found in systeme.io under Settings → Public API Keys.',
                ],
            ],
            self::Mailchimp => [
                [
                    'key' => 'api_key',
                    'label' => 'API key',
                    'type' => 'password',
                    'hint' => 'Account → Extras → API keys. The key ends with a datacenter suffix like -us21.',
                ],
            ],
            self::BirdSend => [
                [
                    'key' => 'api_key',
                    'label' => 'Access token',
                    'type' => 'password',
                    'hint' => 'Create a personal access token in BirdSend under Settings → API.',
                ],
            ],
            // AWeber and GoToWebinar connect via OAuth, so there are no manual
            // credential fields.
            self::AWeber => [],
            self::GoToWebinar => [],
        };
    }
}
