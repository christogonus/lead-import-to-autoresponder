<?php

namespace App\Integrations;

use App\Enums\IntegrationProvider;
use App\Integrations\Contracts\AutoresponderProvider;
use App\Integrations\OAuth\OAuthClient;
use App\Models\Integration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

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
     * How long one worker may hold the token-refresh lock, and how long the
     * others wait on it before giving up.
     */
    private const REFRESH_LOCK_SECONDS = 30;

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
        if (! $this->needsRefresh($integration->credentials)) {
            return $integration->credentials;
        }

        // Every queue worker pushing for this integration reaches here at once
        // when the token nears expiry. Refreshing in parallel mints several
        // tokens, the provider revokes all but one, and the last writer can
        // persist a revoked one ("Invalid token passed"). So one worker
        // refreshes while the rest wait, then reuse what it stored.
        return Cache::lock("integration-oauth-refresh:{$integration->getKey()}", self::REFRESH_LOCK_SECONDS)
            ->block(self::REFRESH_LOCK_SECONDS, function () use ($integration): array {
                $integration->refresh();

                return $this->needsRefresh($integration->credentials)
                    ? $this->refreshCredentials($integration)
                    : $integration->credentials;
            });
    }

    /**
     * Whether the stored access token is expired, or close enough to it that it
     * should be replaced before use.
     *
     * @param  array<string, mixed>  $credentials
     */
    private function needsRefresh(array $credentials): bool
    {
        $expiresAt = isset($credentials['expires_at']) ? Carbon::parse($credentials['expires_at']) : null;

        return $expiresAt === null || ! $expiresAt->isAfter(now()->addSeconds(self::REFRESH_LEEWAY_SECONDS));
    }

    /**
     * Exchange the refresh token for a new access token and persist it.
     *
     * @return array<string, mixed>
     */
    private function refreshCredentials(Integration $integration): array
    {
        $credentials = $integration->credentials;

        $client = new OAuthClient($integration->provider->oauthConfig());

        $tokens = $client->refresh((string) ($credentials['refresh_token'] ?? ''));

        // Also backfills identity-derived fields (e.g. GoToWebinar's organizer
        // key) for connections stored without them.
        $credentials = $client->withIdentityCredentials(array_merge($credentials, $tokens));

        $integration->update([
            'credentials' => $credentials,
            'last_verified_at' => now(),
        ]);

        return $credentials;
    }
}
