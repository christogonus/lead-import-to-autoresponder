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
     * @param  array<string, string>  $extraAuthorizeParams  Extra query params for
     *                                                       the authorization URL
     *                                                       (e.g. Zoho's
     *                                                       access_type=offline).
     * @param  string  $scopeSeparator  Delimiter for the scope string; OAuth2
     *                                  specifies a space, but some providers
     *                                  (Zoho) require commas.
     * @param  bool  $credentialsInBody  Send the client id/secret as form fields
     *                                   rather than HTTP Basic auth.
     * @param  ?string  $identityUrl  Endpoint queried with the access token for
     *                                credentials the token response does not
     *                                carry (e.g. GoToWebinar's organizer key).
     * @param  array<string, string>  $identityFields  Map of identity-response
     *                                                 field to the credential key
     *                                                 it is stored under.
     */
    public function __construct(
        public readonly string $authorizeUrl,
        public readonly string $tokenUrl,
        public readonly string $clientId,
        public readonly string $clientSecret,
        public readonly array $scopes,
        public readonly bool $usesPkce = true,
        public readonly array $extraTokenFields = [],
        public readonly array $extraAuthorizeParams = [],
        public readonly string $scopeSeparator = ' ',
        public readonly bool $credentialsInBody = false,
        public readonly ?string $identityUrl = null,
        public readonly array $identityFields = [],
    ) {}

    /**
     * The delimited scope string used in the authorization request.
     */
    public function scopeString(): string
    {
        return implode($this->scopeSeparator, $this->scopes);
    }
}
