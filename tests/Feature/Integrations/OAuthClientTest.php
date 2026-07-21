<?php

use App\Integrations\Exceptions\IntegrationException;
use App\Integrations\OAuth\OAuthClient;
use App\Integrations\OAuth\OAuthConfig;
use App\Integrations\OAuth\Pkce;
use Illuminate\Support\Facades\Http;

function oauthClient(): OAuthClient
{
    return new OAuthClient(new OAuthConfig(
        authorizeUrl: 'https://auth.example.com/authorize',
        tokenUrl: 'https://auth.example.com/token',
        clientId: 'client-id',
        clientSecret: 'client-secret',
        scopes: ['account.read', 'subscriber.write'],
    ));
}

function oauthClientWith(bool $usesPkce = true, array $scopes = ['account.read'], array $extraTokenFields = []): OAuthClient
{
    return new OAuthClient(new OAuthConfig(
        authorizeUrl: 'https://auth.example.com/authorize',
        tokenUrl: 'https://auth.example.com/token',
        clientId: 'client-id',
        clientSecret: 'client-secret',
        scopes: $scopes,
        usesPkce: $usesPkce,
        extraTokenFields: $extraTokenFields,
    ));
}

test('the authorization url carries the pkce challenge and state', function () {
    $url = oauthClient()->authorizationUrl('state-123', 'challenge-abc', 'https://app.test/callback');

    expect($url)->toStartWith('https://auth.example.com/authorize?')
        ->and($url)->toContain('client_id=client-id')
        ->and($url)->toContain('state=state-123')
        ->and($url)->toContain('code_challenge=challenge-abc')
        ->and($url)->toContain('code_challenge_method=S256')
        ->and($url)->toContain('scope='.urlencode('account.read subscriber.write'));
});

test('the authorization url omits scope and pkce when the config disables them', function () {
    $url = oauthClientWith(usesPkce: false, scopes: [])->authorizationUrl('state-123', null, 'https://app.test/callback');

    expect($url)->toStartWith('https://auth.example.com/authorize?')
        ->and($url)->toContain('state=state-123')
        ->and($url)->not->toContain('scope=')
        ->and($url)->not->toContain('code_challenge');
});

test('exchangeCode omits the verifier for a non-pkce provider', function () {
    Http::fake(['auth.example.com/token' => Http::response(['access_token' => 'a', 'expires_in' => 3600], 200)]);

    oauthClientWith(usesPkce: false)->exchangeCode('the-code', null, 'https://app.test/callback');

    Http::assertSent(fn ($request) => $request['code'] === 'the-code' && ! isset($request['code_verifier']));
});

test('extra token fields are captured from the token response', function () {
    Http::fake(['auth.example.com/token' => Http::response([
        'access_token' => 'a',
        'refresh_token' => 'r',
        'expires_in' => 3600,
        'organizer_key' => 'org-1',
        'account_key' => 'acct-1',
    ], 200)]);

    $tokens = oauthClientWith(extraTokenFields: ['organizer_key', 'account_key'])
        ->exchangeCode('code', null, 'https://app.test/callback');

    expect($tokens['organizer_key'])->toBe('org-1')
        ->and($tokens['account_key'])->toBe('acct-1');
});

test('extra token fields absent from a response are not included', function () {
    Http::fake(['auth.example.com/token' => Http::response([
        'access_token' => 'a',
        'expires_in' => 3600,
    ], 200)]);

    $tokens = oauthClientWith(extraTokenFields: ['organizer_key'])->refresh('old-refresh');

    expect($tokens)->not->toHaveKey('organizer_key');
});

test('exchangeCode posts the code with basic auth and verifier and returns tokens', function () {
    Http::fake(['auth.example.com/token' => Http::response([
        'access_token' => 'access-1',
        'refresh_token' => 'refresh-1',
        'expires_in' => 3600,
    ], 200)]);

    $tokens = oauthClient()->exchangeCode('the-code', 'the-verifier', 'https://app.test/callback');

    expect($tokens['access_token'])->toBe('access-1')
        ->and($tokens['refresh_token'])->toBe('refresh-1')
        ->and($tokens['expires_at'])->not->toBeNull();

    Http::assertSent(fn ($request) => $request->url() === 'https://auth.example.com/token'
        && $request['grant_type'] === 'authorization_code'
        && $request['code'] === 'the-code'
        && $request['code_verifier'] === 'the-verifier'
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('client-id:client-secret')));
});

test('refresh keeps the previous refresh token when the response omits one', function () {
    Http::fake(['auth.example.com/token' => Http::response([
        'access_token' => 'access-2',
        'expires_in' => 3600,
    ], 200)]);

    $tokens = oauthClient()->refresh('old-refresh');

    expect($tokens['access_token'])->toBe('access-2')
        ->and($tokens['refresh_token'])->toBe('old-refresh');

    Http::assertSent(fn ($request) => $request['grant_type'] === 'refresh_token'
        && $request['refresh_token'] === 'old-refresh');
});

test('a token request failure throws', function () {
    Http::fake(['auth.example.com/token' => Http::response(['error' => 'invalid_grant'], 400)]);

    oauthClient()->refresh('bad');
})->throws(IntegrationException::class);

test('pkce challenge is the url-safe sha256 of the verifier', function () {
    $verifier = Pkce::verifier();
    $expected = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

    expect(Pkce::challenge($verifier))->toBe($expected)
        ->and(strlen($verifier))->toBeGreaterThanOrEqual(43);
});
