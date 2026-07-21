<?php

use App\Enums\IntegrationProvider;
use App\Integrations\Drivers\AWeberProvider;
use App\Integrations\IntegrationManager;
use App\Models\Integration;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    config([
        'services.aweber.client_id' => 'test-client',
        'services.aweber.client_secret' => 'test-secret',
    ]);
});

test('the redirect action stashes pkce state and sends the user to aweber', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $response = $this->actingAs($user)->get(route('integrations.oauth.redirect', [
        'current_team' => $team->slug,
        'provider' => 'aweber',
        'name' => 'My AWeber',
    ]));

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toStartWith('https://auth.aweber.com/oauth2/authorize?')
        ->and($response->headers->get('Location'))->toContain('code_challenge_method=S256');

    $this->assertNotNull(session('oauth'));
    expect(session('oauth'))
        ->toMatchArray(['provider' => 'aweber', 'team_id' => $team->id, 'name' => 'My AWeber'])
        ->and(session('oauth.state'))->not->toBeEmpty()
        ->and(session('oauth.code_verifier'))->not->toBeEmpty();
});

test('the redirect action rejects providers that do not use oauth', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('integrations.oauth.redirect', [
        'current_team' => $user->currentTeam->slug,
        'provider' => 'getresponse',
        'name' => 'Nope',
    ]))->assertNotFound();
});

test('the callback exchanges the code and stores the integration', function () {
    Http::fake(['auth.aweber.com/oauth2/token' => Http::response([
        'access_token' => 'access-1',
        'refresh_token' => 'refresh-1',
        'expires_in' => 3600,
    ], 200)]);

    $user = User::factory()->create();
    $team = $user->currentTeam;

    $this->actingAs($user)->withSession(['oauth' => [
        'provider' => 'aweber',
        'team_id' => $team->id,
        'name' => 'My AWeber',
        'state' => 'state-xyz',
        'code_verifier' => 'verifier-xyz',
    ]])->get(route('integrations.oauth.callback', ['state' => 'state-xyz', 'code' => 'auth-code']))
        ->assertRedirect(route('integrations.index', ['current_team' => $team->slug, 'oauth' => 'connected']));

    $integration = Integration::where('team_id', $team->id)->first();

    expect($integration)->not->toBeNull()
        ->and($integration->provider)->toBe(IntegrationProvider::AWeber)
        ->and($integration->name)->toBe('My AWeber')
        ->and($integration->credentials['access_token'])->toBe('access-1')
        ->and($integration->credentials['refresh_token'])->toBe('refresh-1');

    Http::assertSent(fn ($request) => $request['code'] === 'auth-code'
        && $request['code_verifier'] === 'verifier-xyz');
});

test('the callback rejects a mismatched state', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->withSession(['oauth' => [
        'provider' => 'aweber',
        'team_id' => $user->currentTeam->id,
        'name' => 'My AWeber',
        'state' => 'real-state',
        'code_verifier' => 'v',
    ]])->get(route('integrations.oauth.callback', ['state' => 'forged', 'code' => 'x']))
        ->assertForbidden();

    $this->assertDatabaseCount('integrations', 0);
});

test('the callback returns to the page with an error when authorization is denied', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $this->actingAs($user)->withSession(['oauth' => [
        'provider' => 'aweber',
        'team_id' => $team->id,
        'name' => 'My AWeber',
        'state' => 'state-xyz',
        'code_verifier' => 'v',
    ]])->get(route('integrations.oauth.callback', ['state' => 'state-xyz', 'error' => 'access_denied']))
        ->assertRedirect(route('integrations.index', ['current_team' => $team->slug, 'oauth' => 'error']));

    $this->assertDatabaseCount('integrations', 0);
});

test('connecting an oauth provider from the page redirects into the oauth flow', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test('pages::integrations.index')
        ->set('provider', 'aweber')
        ->set('name', 'My AWeber')
        ->call('connect')
        ->assertRedirect(route('integrations.oauth.redirect', [
            'current_team' => $user->currentTeam->slug,
            'provider' => 'aweber',
            'name' => 'My AWeber',
        ]));

    $this->assertDatabaseCount('integrations', 0);
});

test('the manager refreshes and persists an expired oauth token before building the driver', function () {
    Http::fake(['auth.aweber.com/oauth2/token' => Http::response([
        'access_token' => 'fresh-access',
        'refresh_token' => 'fresh-refresh',
        'expires_in' => 3600,
    ], 200)]);

    $integration = Integration::factory()->create([
        'provider' => IntegrationProvider::AWeber,
        'credentials' => [
            'access_token' => 'stale-access',
            'refresh_token' => 'stale-refresh',
            'expires_at' => now()->subMinute()->toIso8601String(),
        ],
    ]);

    $driver = app(IntegrationManager::class)->driver($integration);

    expect($driver)->toBeInstanceOf(AWeberProvider::class);

    $integration->refresh();
    expect($integration->credentials['access_token'])->toBe('fresh-access')
        ->and($integration->credentials['refresh_token'])->toBe('fresh-refresh');

    Http::assertSent(fn ($request) => $request['grant_type'] === 'refresh_token'
        && $request['refresh_token'] === 'stale-refresh');
});

test('the manager does not refresh a token that is still valid', function () {
    Http::fake();

    $integration = Integration::factory()->create([
        'provider' => IntegrationProvider::AWeber,
        'credentials' => [
            'access_token' => 'good-access',
            'refresh_token' => 'good-refresh',
            'expires_at' => now()->addHour()->toIso8601String(),
        ],
    ]);

    app(IntegrationManager::class)->driver($integration);

    Http::assertNothingSent();
});
