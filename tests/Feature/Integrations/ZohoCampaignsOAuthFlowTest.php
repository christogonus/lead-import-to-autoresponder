<?php

use App\Enums\IntegrationProvider;
use App\Integrations\IntegrationManager;
use App\Models\Integration;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.zoho_campaigns.client_id' => 'zoho-client',
        'services.zoho_campaigns.client_secret' => 'zoho-secret',
        'services.zoho_campaigns.region' => 'com',
    ]);
});

test('the redirect asks zoho for offline access so a refresh token is issued', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('integrations.oauth.redirect', [
        'current_team' => $user->currentTeam->slug,
        'provider' => 'zoho_campaigns',
        'name' => 'My Zoho',
    ]));

    $location = $response->headers->get('Location');

    expect($location)->toStartWith('https://accounts.zoho.com/oauth/v2/auth?')
        // Without both of these Zoho issues an access token and no refresh token,
        // and the connection would break an hour later.
        ->and($location)->toContain('access_type=offline')
        ->and($location)->toContain('prompt=consent')
        // Zoho delimits scopes with commas rather than spaces.
        ->and(urldecode($location))->toContain('scope=ZohoCampaigns.contact.READ,ZohoCampaigns.contact.UPDATE');
});

test('the configured region drives the accounts host', function () {
    config(['services.zoho_campaigns.region' => 'eu']);

    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('integrations.oauth.redirect', [
        'current_team' => $user->currentTeam->slug,
        'provider' => 'zoho_campaigns',
        'name' => 'My Zoho',
    ]));

    expect($response->headers->get('Location'))->toStartWith('https://accounts.zoho.eu/oauth/v2/auth?');
});

test('the callback stores the tokens and the api domain', function () {
    Http::fake(['accounts.zoho.com/oauth/v2/token' => Http::response([
        'access_token' => 'access-1',
        'refresh_token' => 'refresh-1',
        'api_domain' => 'https://www.zohoapis.com',
        'token_type' => 'Bearer',
        'expires_in' => 3600,
    ], 200)]);

    $user = User::factory()->create();
    $team = $user->currentTeam;

    $this->actingAs($user)->withSession(['oauth' => [
        'provider' => 'zoho_campaigns',
        'team_id' => $team->id,
        'name' => 'My Zoho',
        'state' => 'state-xyz',
        'code_verifier' => null,
    ]])->get(route('integrations.oauth.callback', ['state' => 'state-xyz', 'code' => 'auth-code']))
        ->assertRedirect(route('integrations.index', ['current_team' => $team->slug, 'oauth' => 'connected']));

    $integration = Integration::where('team_id', $team->id)->first();

    expect($integration)->not->toBeNull()
        ->and($integration->provider)->toBe(IntegrationProvider::ZohoCampaigns)
        ->and($integration->credentials['access_token'])->toBe('access-1')
        ->and($integration->credentials['refresh_token'])->toBe('refresh-1')
        ->and($integration->credentials['api_domain'])->toBe('https://www.zohoapis.com');

    // Zoho takes the client credentials in the body rather than via Basic auth.
    Http::assertSent(fn ($request) => $request['client_id'] === 'zoho-client'
        && $request['client_secret'] === 'zoho-secret'
        && ! $request->hasHeader('Authorization'));
});

test('the callback fails cleanly when zoho reports an error inside a 200 response', function () {
    Http::fake(['accounts.zoho.com/oauth/v2/token' => Http::response([
        'error' => 'invalid_code',
    ], 200)]);

    $user = User::factory()->create();
    $team = $user->currentTeam;

    $this->actingAs($user)->withSession(['oauth' => [
        'provider' => 'zoho_campaigns',
        'team_id' => $team->id,
        'name' => 'My Zoho',
        'state' => 'state-xyz',
        'code_verifier' => null,
    ]])->get(route('integrations.oauth.callback', ['state' => 'state-xyz', 'code' => 'bad-code']))
        ->assertRedirect(route('integrations.index', ['current_team' => $team->slug, 'oauth' => 'error']));

    $this->assertDatabaseCount('integrations', 0);
});

test('a refresh keeps the refresh token and api domain zoho omits from the response', function () {
    Http::fake(['accounts.zoho.com/oauth/v2/token' => Http::response([
        'access_token' => 'access-2',
        'api_domain' => 'https://www.zohoapis.com',
        'expires_in' => 3600,
    ], 200)]);

    $user = User::factory()->create();
    $integration = Integration::factory()->create([
        'team_id' => $user->currentTeam->id,
        'provider' => IntegrationProvider::ZohoCampaigns,
        'credentials' => [
            'access_token' => 'access-1',
            'refresh_token' => 'refresh-1',
            'api_domain' => 'https://www.zohoapis.com',
            'expires_at' => now()->subMinute()->toIso8601String(),
        ],
    ]);

    app(IntegrationManager::class)->driver($integration);

    expect($integration->fresh()->credentials['access_token'])->toBe('access-2')
        // Zoho's refresh tokens do not rotate and are absent from the response.
        ->and($integration->fresh()->credentials['refresh_token'])->toBe('refresh-1');
});
