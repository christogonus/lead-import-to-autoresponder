<?php

namespace App\Http\Controllers;

use App\Enums\IntegrationProvider;
use App\Integrations\Exceptions\IntegrationException;
use App\Integrations\OAuth\OAuthClient;
use App\Integrations\OAuth\Pkce;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Drives the OAuth2 authorization-code connection flow for providers that
 * authenticate via redirect (e.g. AWeber). The flow leaves the app to the
 * provider's consent screen and returns to the team-independent callback, which
 * exchanges the code and stores the integration on the originating team.
 */
class OAuthController extends Controller
{
    /**
     * Begin the OAuth flow: stash PKCE/state in the session and redirect the
     * user to the provider's authorization screen.
     */
    public function redirect(Request $request, Team $current_team, string $provider): RedirectResponse
    {
        $integrationProvider = IntegrationProvider::tryFrom($provider);

        abort_if($integrationProvider === null || ! $integrationProvider->usesOAuth(), 404);

        $validated = $request->validate(['name' => ['required', 'string', 'max:255']]);

        $config = $integrationProvider->oauthConfig();

        $verifier = $config->usesPkce ? Pkce::verifier() : null;
        $state = Str::random(40);

        $request->session()->put('oauth', [
            'provider' => $provider,
            'team_id' => $current_team->id,
            'name' => $validated['name'],
            'state' => $state,
            'code_verifier' => $verifier,
        ]);

        $url = (new OAuthClient($config))->authorizationUrl(
            state: $state,
            codeChallenge: $verifier === null ? null : Pkce::challenge($verifier),
            redirectUri: $this->redirectUri(),
        );

        return redirect()->away($url);
    }

    /**
     * Handle the provider's redirect back: verify state, exchange the code for
     * tokens, and persist the integration on the originating team.
     */
    public function callback(Request $request): RedirectResponse
    {
        $session = $request->session()->pull('oauth');

        abort_if($session === null || ! hash_equals($session['state'], (string) $request->query('state')), 403, 'Invalid OAuth state.');

        $provider = IntegrationProvider::from($session['provider']);
        $team = Team::findOrFail($session['team_id']);

        abort_unless($request->user()->belongsToTeam($team), 403);

        if ($request->query('error') !== null || $request->query('code') === null) {
            return $this->backToIntegrations($team, 'error');
        }

        try {
            $tokens = (new OAuthClient($provider->oauthConfig()))->exchangeCode(
                code: (string) $request->query('code'),
                codeVerifier: $session['code_verifier'],
                redirectUri: $this->redirectUri(),
            );
        } catch (IntegrationException) {
            return $this->backToIntegrations($team, 'error');
        }

        $team->integrations()->create([
            'provider' => $provider,
            'name' => $session['name'],
            'credentials' => $tokens,
            'status' => 'connected',
            'last_verified_at' => now(),
        ]);

        return $this->backToIntegrations($team, 'connected');
    }

    /**
     * The single, team-independent redirect URI registered with the provider.
     */
    private function redirectUri(): string
    {
        return route('integrations.oauth.callback');
    }

    private function backToIntegrations(Team $team, string $oauth): RedirectResponse
    {
        return redirect()->route('integrations.index', [
            'current_team' => $team->slug,
            'oauth' => $oauth,
        ]);
    }
}
