<?php

use App\Enums\IntegrationProvider;
use App\Integrations\IntegrationManager;
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

test('the callback fetches the organizer key from the identity endpoint', function () {
    Http::fake([
        'authentication.logmeininc.com/oauth/token' => Http::response([
            'access_token' => 'access-1',
            'refresh_token' => 'refresh-1',
            'expires_in' => 3600,
        ], 200),
        'api.getgo.com/admin/rest/v1/me' => Http::response([
            'key' => 'org-42',
            'accountKey' => 'acct-9',
            'email' => 'organizer@example.com',
        ], 200),
    ]);

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
    Http::assertSent(fn ($request) => str_contains($request->url(), '/oauth/token')
        && $request['grant_type'] === 'authorization_code'
        && ! isset($request['code_verifier']));

    // The identity lookup authenticates with the freshly-exchanged token.
    Http::assertSent(fn ($request) => $request->url() === 'https://api.getgo.com/admin/rest/v1/me'
        && $request->hasHeader('Authorization', 'Bearer access-1'));
});

test('the callback skips the identity endpoint when the token response carries the organizer key', function () {
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

    expect(Integration::where('team_id', $team->id)->first()->credentials['organizer_key'])->toBe('org-42');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.getgo.com'));
});

test('the callback fails without storing a connection when the identity lookup fails', function () {
    Http::fake([
        'authentication.logmeininc.com/oauth/token' => Http::response([
            'access_token' => 'access-1',
            'refresh_token' => 'refresh-1',
            'expires_in' => 3600,
        ], 200),
        'api.getgo.com/admin/rest/v1/me' => Http::response([], 500),
    ]);

    $user = User::factory()->create();
    $team = $user->currentTeam;

    $this->actingAs($user)->withSession(['oauth' => [
        'provider' => 'gotowebinar',
        'team_id' => $team->id,
        'name' => 'My GoToWebinar',
        'state' => 'state-xyz',
        'code_verifier' => null,
    ]])->get(route('integrations.oauth.callback', ['state' => 'state-xyz', 'code' => 'auth-code']))
        ->assertRedirect(route('integrations.index', ['current_team' => $team->slug, 'oauth' => 'error']));

    $this->assertDatabaseCount('integrations', 0);
});

test('the manager backfills a missing organizer key when refreshing the token', function () {
    Http::fake([
        'authentication.logmeininc.com/oauth/token' => Http::response([
            'access_token' => 'fresh-access',
            'refresh_token' => 'fresh-refresh',
            'expires_in' => 3600,
        ], 200),
        'api.getgo.com/admin/rest/v1/me' => Http::response([
            'key' => 'org-42',
            'accountKey' => 'acct-9',
        ], 200),
    ]);

    $integration = Integration::factory()->create([
        'provider' => IntegrationProvider::GoToWebinar,
        'credentials' => [
            'access_token' => 'stale-access',
            'refresh_token' => 'stale-refresh',
            'expires_at' => now()->subMinute()->toIso8601String(),
        ],
    ]);

    app(IntegrationManager::class)->driver($integration);

    $integration->refresh();
    expect($integration->credentials['access_token'])->toBe('fresh-access')
        ->and($integration->credentials['organizer_key'])->toBe('org-42')
        ->and($integration->credentials['account_key'])->toBe('acct-9');
});

test('the manager does not query the identity endpoint when the organizer key is stored', function () {
    Http::fake([
        'authentication.logmeininc.com/oauth/token' => Http::response([
            'access_token' => 'fresh-access',
            'refresh_token' => 'fresh-refresh',
            'expires_in' => 3600,
        ], 200),
    ]);

    $integration = Integration::factory()->create([
        'provider' => IntegrationProvider::GoToWebinar,
        'credentials' => [
            'access_token' => 'stale-access',
            'refresh_token' => 'stale-refresh',
            'expires_at' => now()->subMinute()->toIso8601String(),
            'organizer_key' => 'org-42',
            'account_key' => 'acct-9',
        ],
    ]);

    app(IntegrationManager::class)->driver($integration);

    $integration->refresh();
    expect($integration->credentials['organizer_key'])->toBe('org-42');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.getgo.com'));
});
