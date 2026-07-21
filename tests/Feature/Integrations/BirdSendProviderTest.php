<?php

use App\Integrations\Drivers\BirdSendProvider;
use App\Integrations\Support\ContactPayload;
use Illuminate\Support\Facades\Http;

function birdSend(array $credentials = ['api_key' => 'test-token']): BirdSendProvider
{
    return new BirdSendProvider($credentials);
}

test('verify returns true for valid credentials', function () {
    Http::fake(['api.birdsend.co/v1/tags*' => Http::response(['data' => [], 'meta' => ['last_page' => 1]], 200)]);

    expect(birdSend()->verify())->toBeTrue();

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer test-token'));
});

test('verify returns false for rejected credentials', function () {
    Http::fake(['api.birdsend.co/v1/tags*' => Http::response(['message' => 'Unauthenticated.'], 401)]);

    expect(birdSend(['api_key' => 'bad'])->verify())->toBeFalse();
});

test('lists maps tags across pages using the tag name as the identifier', function () {
    Http::fake(['api.birdsend.co/v1/tags*' => Http::sequence()
        ->push(['data' => [['tag_id' => 1, 'name' => 'Leads'], ['tag_id' => 2, 'name' => 'Customers']], 'meta' => ['current_page' => 1, 'last_page' => 2]], 200)
        ->push(['data' => [['tag_id' => 3, 'name' => 'VIP']], 'meta' => ['current_page' => 2, 'last_page' => 2]], 200)]);

    $lists = birdSend()->lists();

    expect($lists)->toHaveCount(3)
        ->and($lists[0]->id)->toBe('Leads')
        ->and($lists[0]->name)->toBe('Leads')
        ->and($lists[2]->id)->toBe('VIP');
});

test('pushContact creates the contact with the tag attached in one request', function () {
    Http::fake(['api.birdsend.co/v1/contacts' => Http::response(['contact_id' => 12], 201)]);

    $result = birdSend()->pushContact('Leads', new ContactPayload(
        email: 'jane@example.com',
        firstName: 'Jane',
        lastName: 'Doe',
        phone: '555-1234',
    ));

    expect($result->successful)->toBeTrue()
        ->and($result->remoteId)->toBe('12');

    Http::assertSent(fn ($request) => $request->url() === 'https://api.birdsend.co/v1/contacts'
        && $request->method() === 'POST'
        && $request['email'] === 'jane@example.com'
        && $request['fields']['first_name'] === 'Jane'
        && $request['fields']['phone'] === '555-1234'
        && $request['tags'] === ['Leads']);
});

test('pushContact omits blank fields', function () {
    Http::fake(['api.birdsend.co/v1/contacts' => Http::response(['contact_id' => 12], 201)]);

    birdSend()->pushContact('Leads', new ContactPayload(email: 'bare@example.com'));

    Http::assertSent(fn ($request) => $request['fields'] === []);
});

test('pushContact reuses an existing contact when creation reports a duplicate', function () {
    Http::fake([
        'api.birdsend.co/v1/contacts/*/tags' => Http::response(['contact_id' => 111], 200),
        'api.birdsend.co/v1/contacts?keyword=*' => Http::response(['data' => [['contact_id' => 111]]], 200),
        'api.birdsend.co/v1/contacts' => Http::response(['message' => 'Email already used'], 422),
    ]);

    $result = birdSend()->pushContact('Leads', new ContactPayload(email: 'existing@example.com'));

    expect($result->successful)->toBeTrue()
        ->and($result->remoteId)->toBe('111');

    Http::assertSent(fn ($request) => $request->url() === 'https://api.birdsend.co/v1/contacts/111/tags'
        && $request['tags'] === ['Leads']);
});

test('pushContact returns a failure when the contact cannot be created or found', function () {
    Http::fake([
        'api.birdsend.co/v1/contacts?keyword=*' => Http::response(['data' => []], 200),
        'api.birdsend.co/v1/contacts' => Http::response(['message' => 'Invalid email address'], 422),
    ]);

    $result = birdSend()->pushContact('Leads', new ContactPayload(email: 'nope'));

    expect($result->successful)->toBeFalse()
        ->and($result->error)->toBe('Invalid email address');
});
