<?php

namespace App\Integrations\OAuth;

/**
 * Static OAuth2 configuration for a provider: its endpoints, the app's client
 * credentials, and the scopes to request. Built by IntegrationProvider from the
 * provider's known endpoints and the app-level credentials in config/services.
 */
class OAuthConfig
{
    /**
     * @param  array<int, string>  $scopes  Scopes to request; empty when the
     *                                      provider derives scopes from the
     *                                      OAuth client configuration instead.
     * @param  bool  $usesPkce  Whether to use PKCE (RFC-7636) in the flow.
     * @param  array<int, string>  $extraTokenFields  Additional keys to persist
     *                                                from the token response
     *                                                (e.g. an organizer key).
     */
    public function __construct(
        public readonly string $authorizeUrl,
        public readonly string $tokenUrl,
        public readonly string $clientId,
        public readonly string $clientSecret,
        public readonly array $scopes,
        public readonly bool $usesPkce = true,
        public readonly array $extraTokenFields = [],
    ) {}

    /**
     * The space-delimited scope string used in the authorization request.
     */
    public function scopeString(): string
    {
        return implode(' ', $this->scopes);
    }
}
