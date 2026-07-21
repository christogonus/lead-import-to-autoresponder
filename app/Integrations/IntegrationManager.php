<?php

namespace App\Integrations;

use App\Enums\IntegrationProvider;
use App\Integrations\Contracts\AutoresponderProvider;
use App\Integrations\OAuth\OAuthClient;
use App\Models\Integration;
use Illuminate\Support\Carbon;

/**
 * Resolves the concrete provider driver for a connected integration (or for a
 * set of raw credentials during the connection flow).
 */
class IntegrationManager
{
    /**
     * Refresh an OAuth access token this many seconds before it actually expires
     * to absorb clock skew and in-flight request time.
     */
    private const REFRESH_LEEWAY_SECONDS = 300;

    /**
     * Build the driver for a stored integration, refreshing OAuth tokens first
     * when they are expired or close to expiring.
     */
    public function driver(Integration $integration): AutoresponderProvider
    {
        $credentials = $integration->provider->usesOAuth()
            ? $this->freshOAuthCredentials($integration)
            : $integration->credentials;

        return $this->make($integration->provider, $credentials);
    }

    /**
     * Build a driver for the given provider and raw credentials. Useful for
     * verifying a connection before persisting it.
     *
     * @param  array<string, mixed>  $credentials
     */
    public function make(IntegrationProvider $provider, array $credentials): AutoresponderProvider
    {
        $driver = $provider->driver();

        return new $driver($credentials);
    }

    /**
     * Return the integration's OAuth credentials, refreshing and persisting a new
     * access token when the current one is expired or nearly so.
     *
     * @return array<string, mixed>
     */
    private function freshOAuthCredentials(Integration $integration): array
    {
        $credentials = $integration->credentials;
        $expiresAt = isset($credentials['expires_at']) ? Carbon::parse($credentials['expires_at']) : null;

        if ($expiresAt !== null && $expiresAt->isAfter(now()->addSeconds(self::REFRESH_LEEWAY_SECONDS))) {
            return $credentials;
        }

        $tokens = (new OAuthClient($integration->provider->oauthConfig()))
            ->refresh((string) ($credentials['refresh_token'] ?? ''));

        $credentials = array_merge($credentials, $tokens);

        $integration->update([
            'credentials' => $credentials,
            'last_verified_at' => now(),
        ]);

        return $credentials;
    }
}
