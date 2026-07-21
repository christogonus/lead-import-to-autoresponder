<?php

use App\Enums\IntegrationProvider;
use App\Models\Integration;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.gotowebinar.client_id' => 'goto-client',
        'services.gotowebinar.client_secret' => 'goto-secret',
    ]);
});

test('the redirect sends the user to GoTo without scope or pkce', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('integrations.oauth.redirect', [
        'current_team' => $user->currentTeam->slug,
        'provider' => 'gotowebinar',
        'name' => 'My GoToWebinar',
    ]));

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toStartWith('https://authentication.logmeininc.com/oauth/authorize?')
        ->and($response->headers->get('Location'))->not->toContain('code_challenge')
        ->and($response->headers->get('Location'))->not->toContain('scope=');

    // No PKCE verifier is generated for a non-PKCE provider.
    expect(session('oauth.code_verifier'))->toBeNull();
});

test('the callback stores the organizer key returned with the tokens', function () {
    Http::fake(['authentication.logmeininc.com/oauth/token' => Http::response([
        'access_token' => 'access-1',
        'refresh_token' => 'refresh-1',
        'expires_in' => 3600,
        'organizer_key' => 'org-42',
        'account_key' => 'acct-9',
    ], 200)]);

    $user = User::factory()->create();
    $team = $user->currentTeam;

    $this->actingAs($user)->withSession(['oauth' => [
        'provider' => 'gotowebinar',
        'team_id' => $team->id,
        'name' => 'My GoToWebinar',
        'state' => 'state-xyz',
        'code_verifier' => null,
    ]])->get(route('integrations.oauth.callback', ['state' => 'state-xyz', 'code' => 'auth-code']))
        ->assertRedirect(route('integrations.index', ['current_team' => $team->slug, 'oauth' => 'connected']));

    $integration = Integration::where('team_id', $team->id)->first();

    expect($integration)->not->toBeNull()
        ->and($integration->provider)->toBe(IntegrationProvider::GoToWebinar)
        ->and($integration->credentials['access_token'])->toBe('access-1')
        ->and($integration->credentials['organizer_key'])->toBe('org-42')
        ->and($integration->credentials['account_key'])->toBe('acct-9');

    // Confidential client: no PKCE verifier is sent in the exchange.
    Http::assertSent(fn ($request) => $request['grant_type'] === 'authorization_code'
        && ! isset($request['code_verifier']));
});
