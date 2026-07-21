<?php

use App\Integrations\Drivers\AWeberProvider;
use App\Integrations\Support\ContactPayload;
use Illuminate\Support\Facades\Http;

function aweber(array $credentials = ['access_token' => 'test-token']): AWeberProvider
{
    return new AWeberProvider($credentials);
}

test('verify returns true for a valid access token', function () {
    Http::fake(['api.aweber.com/1.0/accounts' => Http::response(['entries' => [['id' => 1]]], 200)]);

    expect(aweber()->verify())->toBeTrue();

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer test-token'));
});

test('verify returns false when the token is rejected', function () {
    Http::fake(['api.aweber.com/1.0/accounts' => Http::response(['error' => ['message' => 'Unauthorized']], 401)]);

    expect(aweber(['access_token' => 'bad'])->verify())->toBeFalse();
});

test('lists resolves the account then maps lists across pages', function () {
    Http::fake([
        'api.aweber.com/1.0/accounts/7/lists*' => Http::sequence()
            ->push([
                'entries' => [['id' => 10, 'name' => 'Newsletter'], ['id' => 11, 'name' => 'Promos']],
                'next_collection_link' => 'https://api.aweber.com/1.0/accounts/7/lists?ws.start=100',
            ], 200)
            ->push(['entries' => [['id' => 12, 'name' => 'VIP']]], 200),
        'api.aweber.com/1.0/accounts' => Http::response(['entries' => [['id' => 7]]], 200),
    ]);

    $lists = aweber()->lists();

    expect($lists)->toHaveCount(3)
        ->and($lists[0]->id)->toBe('7:10')
        ->and($lists[0]->name)->toBe('Newsletter')
        ->and($lists[2]->id)->toBe('7:12');
});

test('pushContact creates the subscriber with update_existing and returns its id', function () {
    Http::fake(['api.aweber.com/1.0/accounts/7/lists/10/subscribers' => Http::response(null, 201, [
        'Location' => 'https://api.aweber.com/1.0/accounts/7/lists/10/subscribers/999',
    ])]);

    $result = aweber()->pushContact('7:10', new ContactPayload(
        email: 'jane@example.com',
        firstName: 'Jane',
        lastName: 'Doe',
    ));

    expect($result->successful)->toBeTrue()
        ->and($result->remoteId)->toBe('999');

    Http::assertSent(fn ($request) => $request->url() === 'https://api.aweber.com/1.0/accounts/7/lists/10/subscribers'
        && $request->method() === 'POST'
        && $request['email'] === 'jane@example.com'
        && $request['name'] === 'Jane Doe'
        && $request['update_existing'] === true);
});

test('pushContact maps phone and country to matching existing custom fields', function () {
    Http::fake([
        'api.aweber.com/1.0/accounts/7/lists/10/custom_fields*' => Http::response([
            'entries' => [['id' => 1, 'name' => 'Phone'], ['id' => 2, 'name' => 'Country']],
        ], 200),
        'api.aweber.com/1.0/accounts/7/lists/10/subscribers' => Http::response(null, 201, [
            'Location' => 'https://api.aweber.com/1.0/accounts/7/lists/10/subscribers/999',
        ]),
    ]);

    $result = aweber()->pushContact('7:10', new ContactPayload(
        email: 'jane@example.com',
        phone: '555-1234',
        country: 'US',
    ));

    expect($result->successful)->toBeTrue();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/lists/10/custom_fields'));

    Http::assertSent(fn ($request) => $request->url() === 'https://api.aweber.com/1.0/accounts/7/lists/10/subscribers'
        && $request['custom_fields'] === ['Phone' => '555-1234', 'Country' => 'US']);
});

test('pushContact matches custom field names case-insensitively', function () {
    Http::fake([
        'api.aweber.com/1.0/accounts/7/lists/10/custom_fields*' => Http::response([
            'entries' => [['id' => 1, 'name' => 'PHONE']],
        ], 200),
        'api.aweber.com/1.0/accounts/7/lists/10/subscribers' => Http::response(null, 201),
    ]);

    aweber()->pushContact('7:10', new ContactPayload(email: 'jane@example.com', phone: '555-1234'));

    // The value is sent under the field's actual name, not the lower-cased key.
    Http::assertSent(fn ($request) => isset($request['custom_fields'])
        && $request['custom_fields'] === ['PHONE' => '555-1234']);
});

test('pushContact skips custom fields that do not exist on the list', function () {
    Http::fake([
        'api.aweber.com/1.0/accounts/7/lists/10/custom_fields*' => Http::response([
            'entries' => [['id' => 1, 'name' => 'Phone']],
        ], 200),
        'api.aweber.com/1.0/accounts/7/lists/10/subscribers' => Http::response(null, 201),
    ]);

    aweber()->pushContact('7:10', new ContactPayload(
        email: 'jane@example.com',
        phone: '555-1234',
        country: 'US',
    ));

    // Country has no matching custom field on the list, so it is omitted.
    Http::assertSent(fn ($request) => isset($request['custom_fields'])
        && $request['custom_fields'] === ['Phone' => '555-1234']);
});

test('pushContact does not fetch custom fields when phone and country are absent', function () {
    Http::fake(['api.aweber.com/1.0/accounts/7/lists/10/subscribers' => Http::response(null, 201)]);

    aweber()->pushContact('7:10', new ContactPayload(email: 'jane@example.com', firstName: 'Jane'));

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/custom_fields'));
    Http::assertSent(fn ($request) => ! isset($request['custom_fields']));
});

test('pushContact treats an existing subscriber as success', function () {
    Http::fake(['api.aweber.com/1.0/accounts/7/lists/10/subscribers' => Http::response([
        'error' => ['message' => 'That subscriber is already subscribed to this list.'],
    ], 400)]);

    $result = aweber()->pushContact('7:10', new ContactPayload(email: 'existing@example.com'));

    expect($result->successful)->toBeTrue()
        ->and($result->remoteId)->toBeNull();
});

test('pushContact returns a failure for other errors', function () {
    Http::fake(['api.aweber.com/1.0/accounts/7/lists/10/subscribers' => Http::response([
        'error' => ['message' => 'email: This value is not a valid email address.'],
    ], 400)]);

    $result = aweber()->pushContact('7:10', new ContactPayload(email: 'nope'));

    expect($result->successful)->toBeFalse()
        ->and($result->error)->toBe('email: This value is not a valid email address.');
});

test('pushContact fails cleanly on a malformed list identifier', function () {
    $result = aweber()->pushContact('no-colon', new ContactPayload(email: 'jane@example.com'));

    expect($result->successful)->toBeFalse();

    Http::assertNothingSent();
});
