<?php

use App\Integrations\Drivers\SystemeIoProvider;
use App\Integrations\Support\ContactPayload;
use Illuminate\Support\Facades\Http;

function systemeIo(array $credentials = ['api_key' => 'test-key']): SystemeIoProvider
{
    return new SystemeIoProvider($credentials);
}

test('verify returns true for valid credentials', function () {
    Http::fake(['api.systeme.io/api/tags*' => Http::response(['items' => [], 'hasMore' => false], 200)]);

    expect(systemeIo()->verify())->toBeTrue();

    Http::assertSent(fn ($request) => $request->hasHeader('X-API-Key', 'test-key'));
});

test('verify returns false for rejected credentials', function () {
    Http::fake(['api.systeme.io/api/tags*' => Http::response(['message' => 'Unauthorized'], 401)]);

    expect(systemeIo(['api_key' => 'bad'])->verify())->toBeFalse();
});

test('lists maps tags across pages to remote lists', function () {
    Http::fake(['api.systeme.io/api/tags*' => Http::sequence()
        ->push(['items' => [['id' => 1, 'name' => 'Leads'], ['id' => 2, 'name' => 'Customers']], 'hasMore' => true], 200)
        ->push(['items' => [['id' => 3, 'name' => 'VIP']], 'hasMore' => false], 200)]);

    $lists = systemeIo()->lists();

    expect($lists)->toHaveCount(3)
        ->and($lists[0]->id)->toBe('1')
        ->and($lists[0]->name)->toBe('Leads')
        ->and($lists[2]->name)->toBe('VIP');
});

test('pushContact creates the contact then assigns the tag', function () {
    Http::fake([
        'api.systeme.io/api/contacts/*/tags' => Http::response(null, 204),
        'api.systeme.io/api/contacts' => Http::response(['id' => 987], 201),
    ]);

    $result = systemeIo()->pushContact('55', new ContactPayload(
        email: 'jane@example.com',
        firstName: 'Jane',
        lastName: 'Doe',
        phone: '555-1234',
        country: 'US',
    ));

    expect($result->successful)->toBeTrue()
        ->and($result->remoteId)->toBe('987');

    Http::assertSent(fn ($request) => $request->url() === 'https://api.systeme.io/api/contacts'
        && $request->method() === 'POST'
        && $request['email'] === 'jane@example.com'
        && collect($request['fields'])->contains(fn ($f) => $f['slug'] === 'first_name' && $f['value'] === 'Jane')
        && collect($request['fields'])->contains(fn ($f) => $f['slug'] === 'phone' && $f['value'] === '555-1234'));

    Http::assertSent(fn ($request) => $request->url() === 'https://api.systeme.io/api/contacts/987/tags'
        && $request['tagId'] === 55);
});

test('pushContact reuses an existing contact when creation reports a duplicate', function () {
    Http::fake([
        'api.systeme.io/api/contacts/*/tags' => Http::response(null, 204),
        'api.systeme.io/api/contacts?email=*' => Http::response(['items' => [['id' => 111]], 'hasMore' => false], 200),
        'api.systeme.io/api/contacts' => Http::response(['message' => 'Email already used'], 422),
    ]);

    $result = systemeIo()->pushContact('55', new ContactPayload(email: 'existing@example.com'));

    expect($result->successful)->toBeTrue()
        ->and($result->remoteId)->toBe('111');

    Http::assertSent(fn ($request) => $request->url() === 'https://api.systeme.io/api/contacts/111/tags'
        && $request['tagId'] === 55);
});

test('pushContact returns a failure when the contact cannot be created or found', function () {
    Http::fake([
        'api.systeme.io/api/contacts?email=*' => Http::response(['items' => [], 'hasMore' => false], 200),
        'api.systeme.io/api/contacts' => Http::response(['message' => 'Invalid email address'], 422),
    ]);

    $result = systemeIo()->pushContact('55', new ContactPayload(email: 'nope'));

    expect($result->successful)->toBeFalse()
        ->and($result->error)->toBe('Invalid email address');
});
