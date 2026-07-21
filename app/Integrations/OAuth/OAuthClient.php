<?php

namespace App\Integrations\OAuth;

use App\Integrations\Exceptions\IntegrationException;
use Illuminate\Support\Facades\Http;

/**
 * A provider-agnostic OAuth2 authorization-code (with PKCE) client. It builds
 * authorization URLs and exchanges/refreshes tokens against the endpoints in an
 * OAuthConfig, so a new OAuth provider only needs its own OAuthConfig.
 */
class OAuthClient
{
    public function __construct(
        private readonly OAuthConfig $config,
    ) {}

    /**
     * Build the URL the user is sent to in order to authorize the integration.
     * The scope and PKCE challenge are omitted when the provider does not use
     * them.
     */
    public function authorizationUrl(string $state, ?string $codeChallenge, string $redirectUri): string
    {
        $query = [
            'response_type' => 'code',
            'client_id' => $this->config->clientId,
            'redirect_uri' => $redirectUri,
            'state' => $state,
        ];

        if ($this->config->scopeString() !== '') {
            $query['scope'] = $this->config->scopeString();
        }

        if ($codeChallenge !== null) {
            $query['code_challenge'] = $codeChallenge;
            $query['code_challenge_method'] = 'S256';
        }

        // e.g. Zoho only issues a refresh token when access_type=offline is asked
        // for here, and re-issues it only when prompt=consent is also present.
        $query = array_merge($query, $this->config->extraAuthorizeParams);

        return $this->config->authorizeUrl.'?'.http_build_query($query);
    }

    /**
     * Exchange an authorization code for an access/refresh token set.
     *
     * @return array<string, mixed>
     */
    public function exchangeCode(string $code, ?string $codeVerifier, string $redirectUri): array
    {
        $body = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
        ];

        if ($codeVerifier !== null) {
            $body['code_verifier'] = $codeVerifier;
        }

        return $this->tokenRequest($body);
    }

    /**
     * Obtain a fresh access token using a refresh token.
     *
     * @return array<string, mixed>
     */
    public function refresh(string $refreshToken): array
    {
        return $this->tokenRequest([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);
    }

    /**
     * @param  array<string, string>  $body
     * @return array<string, mixed>
     */
    private function tokenRequest(array $body): array
    {
        $request = Http::asForm()->acceptJson();

        if ($this->config->credentialsInBody) {
            $body['client_id'] = $this->config->clientId;
            $body['client_secret'] = $this->config->clientSecret;
        } else {
            $request = $request->withBasicAuth($this->config->clientId, $this->config->clientSecret);
        }

        $response = $request->post($this->config->tokenUrl, $body);

        if ($response->failed()) {
            throw IntegrationException::requestFailed('oauth', $response->json('error_description')
                ?? $response->json('error')
                ?? 'HTTP '.$response->status());
        }

        // Zoho answers a rejected grant with HTTP 200 and an "error" key, so a
        // successful status alone is not proof the exchange worked.
        if ($response->json('error') !== null) {
            throw IntegrationException::requestFailed('oauth', (string) $response->json('error'));
        }

        $credentials = [
            'access_token' => (string) $response->json('access_token'),
            // Some providers rotate refresh tokens; fall back to the one we sent
            // when a grant response omits it.
            'refresh_token' => $response->json('refresh_token') ?? ($body['refresh_token'] ?? null),
            'expires_at' => now()->addSeconds((int) $response->json('expires_in', 3600))->toIso8601String(),
        ];

        // Persist any provider-specific fields (e.g. GoToWebinar's organizer_key)
        // only when present, so a refresh response that omits them does not wipe
        // the values captured at the initial exchange.
        foreach ($this->config->extraTokenFields as $field) {
            $value = $response->json($field);

            if ($value !== null) {
                $credentials[$field] = $value;
            }
        }

        return $credentials;
    }
}
