<?php

namespace App\Enums;

use App\Integrations\Contracts\AutoresponderProvider;
use App\Integrations\Drivers\AWeberProvider;
use App\Integrations\Drivers\BirdSendProvider;
use App\Integrations\Drivers\GetResponseProvider;
use App\Integrations\Drivers\GoToWebinarProvider;
use App\Integrations\Drivers\MailchimpProvider;
use App\Integrations\Drivers\SenderNetProvider;
use App\Integrations\Drivers\SendPulseProvider;
use App\Integrations\Drivers\SendXProvider;
use App\Integrations\Drivers\SystemeIoProvider;
use App\Integrations\Drivers\ZohoCampaignsProvider;
use App\Integrations\OAuth\OAuthConfig;

enum IntegrationProvider: string
{
    case GetResponse = 'getresponse';
    case SystemeIo = 'systeme_io';
    case Mailchimp = 'mailchimp';
    case BirdSend = 'birdsend';
    case AWeber = 'aweber';
    case GoToWebinar = 'gotowebinar';
    case ZohoCampaigns = 'zoho_campaigns';
    case SenderNet = 'sender_net';
    case SendX = 'sendx';
    case SendPulse = 'sendpulse';

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
            self::ZohoCampaigns => 'Zoho Campaigns',
            self::SenderNet => 'Sender.net',
            self::SendX => 'SendX',
            self::SendPulse => 'SendPulse',
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
            self::ZohoCampaigns => 'mailing list',
            self::SenderNet => 'group',
            self::SendX => 'list',
            self::SendPulse => 'mailing list',
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
            self::ZohoCampaigns => ZohoCampaignsProvider::class,
            self::SenderNet => SenderNetProvider::class,
            self::SendX => SendXProvider::class,
            self::SendPulse => SendPulseProvider::class,
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
                // client (client secret) rather than PKCE. The organizer key every
                // API call needs is absent from the modern token response (only the
                // legacy Citrix endpoint included it), so it is fetched from the
                // admin "me" endpoint instead.
                scopes: [],
                usesPkce: false,
                extraTokenFields: ['organizer_key', 'account_key'],
                identityUrl: 'https://api.getgo.com/admin/rest/v1/me',
                identityFields: ['key' => 'organizer_key', 'accountKey' => 'account_key'],
            ),
            self::ZohoCampaigns => new OAuthConfig(
                authorizeUrl: 'https://accounts.zoho.'.self::zohoRegion().'/oauth/v2/auth',
                tokenUrl: 'https://accounts.zoho.'.self::zohoRegion().'/oauth/v2/token',
                clientId: (string) config('services.zoho_campaigns.client_id'),
                clientSecret: (string) config('services.zoho_campaigns.client_secret'),
                scopes: ['ZohoCampaigns.contact.READ', 'ZohoCampaigns.contact.UPDATE'],
                // Zoho's own docs use a confidential client with the credentials
                // in the token request body, and only return a refresh token when
                // offline access is requested — without it the connection dies an
                // hour after it is made. api_domain pins the account's data centre.
                usesPkce: false,
                extraTokenFields: ['api_domain'],
                extraAuthorizeParams: ['access_type' => 'offline', 'prompt' => 'consent'],
                scopeSeparator: ',',
                credentialsInBody: true,
            ),
            default => null,
        };
    }

    /**
     * Zoho's data centres are wholly separate — accounts host, API host and the
     * OAuth app registration itself are all per-region, and tokens do not work
     * across them. One region is configured per deployment.
     */
    private static function zohoRegion(): string
    {
        return (string) config('services.zoho_campaigns.region', 'com');
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
            self::SenderNet => [
                [
                    'key' => 'api_key',
                    'label' => 'API access token',
                    'type' => 'password',
                    'hint' => 'Create one in Sender under Settings → API access tokens (app.sender.net/settings/tokens).',
                ],
            ],
            self::SendX => [
                [
                    'key' => 'api_key',
                    'label' => 'Team API key',
                    'type' => 'password',
                    'hint' => 'Found in SendX under Settings → API & Webhooks → Team API Key (app.sendx.io/setting/connectors/api).',
                ],
            ],
            self::SendPulse => [
                [
                    'key' => 'api_key',
                    'label' => 'API key',
                    'type' => 'password',
                    'hint' => 'Create one in SendPulse under Settings → API → API keys. The API ID and Secret are not used.',
                ],
            ],
            // AWeber and GoToWebinar connect via OAuth, so there are no manual
            // credential fields.
            self::AWeber => [],
            self::GoToWebinar => [],
            self::ZohoCampaigns => [],
        };
    }
}
