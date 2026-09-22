<?php

use App\Integrations\Drivers\SendXProvider;
use App\Integrations\Exceptions\IntegrationException;
use App\Integrations\Support\ContactPayload;
use Illuminate\Support\Facades\Http;

function sendX(array $credentials = ['api_key' => 'test-key']): SendXProvider
{
    return new SendXProvider($credentials);
}

test('verify returns true for valid credentials', function () {
    Http::fake(['api.sendx.io/api/v1/rest/list*' => Http::response([], 200)]);

    expect(sendX()->verify())->toBeTrue();

    Http::assertSent(fn ($request) => $request->hasHeader('X-Team-ApiKey', 'test-key'));
});

test('verify returns false for rejected credentials', function () {
    Http::fake(['api.sendx.io/api/v1/rest/list*' => Http::response([
        'status' => 401,
        'message' => 'The Team ID or API Key specified is not valid',
    ], 401)]);

    expect(sendX(['api_key' => 'bad'])->verify())->toBeFalse();
});

test('lists maps lists across pages', function () {
    $fullPage = collect(range(1, 100))
        ->map(fn (int $i): array => ['id' => "list_{$i}", 'name' => "List {$i}"])
        ->all();

    Http::fake(['api.sendx.io/api/v1/rest/list*' => Http::sequence()
        ->push($fullPage, 200)
        ->push([['id' => 'list_OcuxJHdiAvujmwQVJfd3ss', 'name' => 'Newsletter']], 200)]);

    $lists = sendX()->lists();

    expect($lists)->toHaveCount(101)
        ->and($lists[0]->id)->toBe('list_1')
        ->and($lists[100]->id)->toBe('list_OcuxJHdiAvujmwQVJfd3ss')
        ->and($lists[100]->name)->toBe('Newsletter');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'offset=100'));
});

test('lists throws when the request is rejected', function () {
    Http::fake(['api.sendx.io/api/v1/rest/list*' => Http::response([
        'status' => 401,
        'message' => 'The Team ID or API Key specified is not valid',
    ], 401)]);

    expect(fn () => sendX()->lists())
        ->toThrow(IntegrationException::class, 'The Team ID or API Key specified is not valid');
});

test('pushContact creates the contact straight into the list', function () {
    Http::fake(['api.sendx.io/api/v1/rest/contact' => Http::response([
        'id' => 'contact_BnKjkbBBS500CoBCP0oChQ',
        'email' => 'jane@example.com',
    ], 201)]);

    $result = sendX()->pushContact('list_OcuxJHdiAvujmwQVJfd3ss', new ContactPayload(
        email: 'jane@example.com',
        firstName: 'Jane',
        lastName: 'Doe',
        phone: '+15551234567',
        country: 'US',
    ));

    expect($result->successful)->toBeTrue()
        ->and($result->remoteId)->toBe('contact_BnKjkbBBS500CoBCP0oChQ');

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && $request->url() === 'https://api.sendx.io/api/v1/rest/contact'
        && $request->data() === [
            'email' => 'jane@example.com',
            'firstName' => 'Jane',
            'lastName' => 'Doe',
            'lists' => ['list_OcuxJHdiAvujmwQVJfd3ss'],
        ]);
});

test('pushContact omits names the contact does not carry', function () {
    Http::fake(['api.sendx.io/api/v1/rest/contact' => Http::response(['id' => 'contact_x'], 201)]);

    sendX()->pushContact('list_a', new ContactPayload(email: 'jane@example.com'));

    Http::assertSent(fn ($request) => ! array_key_exists('firstName', $request->data())
        && ! array_key_exists('lastName', $request->data()));
});

test('pushContact adds an existing contact to the list while keeping its other lists', function () {
    Http::fake([
        'api.sendx.io/api/v1/rest/contact' => Http::response(['status' => 'error', 'message' => 'email already exists in the team'], 409),
        'api.sendx.io/api/v1/rest/contact?*' => Http::response([
            ['id' => 'contact_other', 'email' => 'jane@example.com.au', 'lists' => []],
            ['id' => 'contact_jane', 'email' => 'Jane@Example.com', 'lists' => ['list_old']],
        ], 200),
        'api.sendx.io/api/v1/rest/contact/contact_jane' => Http::response(['id' => 'contact_jane'], 200),
    ]);

    $result = sendX()->pushContact('list_new', new ContactPayload(email: 'jane@example.com', firstName: 'Jane'));

    expect($result->successful)->toBeTrue()
        ->and($result->remoteId)->toBe('contact_jane');

    Http::assertSent(fn ($request) => $request->method() === 'PUT'
        && $request->url() === 'https://api.sendx.io/api/v1/rest/contact/contact_jane'
        && $request->data() === ['lists' => ['list_old', 'list_new']]);
});

test('pushContact does not duplicate a list the existing contact is already on', function () {
    Http::fake([
        'api.sendx.io/api/v1/rest/contact' => Http::response(['message' => 'email already exists in the team'], 409),
        'api.sendx.io/api/v1/rest/contact?*' => Http::response([
            ['id' => 'contact_jane', 'email' => 'jane@example.com', 'lists' => ['list_a']],
        ], 200),
        'api.sendx.io/api/v1/rest/contact/contact_jane' => Http::response(['id' => 'contact_jane'], 200),
    ]);

    expect(sendX()->pushContact('list_a', new ContactPayload(email: 'jane@example.com'))->successful)->toBeTrue();

    Http::assertSent(fn ($request) => $request->method() === 'PUT' && $request->data() === ['lists' => ['list_a']]);
});

test('pushContact reports the duplicate when the existing contact cannot be found', function () {
    Http::fake([
        'api.sendx.io/api/v1/rest/contact' => Http::response(['message' => 'email already exists in the team'], 409),
        'api.sendx.io/api/v1/rest/contact?*' => Http::response([], 200),
    ]);

    $result = sendX()->pushContact('list_a', new ContactPayload(email: 'jane@example.com'));

    expect($result->successful)->toBeFalse()
        ->and($result->error)->toBe('email already exists in the team')
        ->and($result->context['status'])->toBe(409);
});

test('pushContact reports a failed update of an existing contact', function () {
    Http::fake([
        'api.sendx.io/api/v1/rest/contact' => Http::response(['message' => 'email already exists in the team'], 409),
        'api.sendx.io/api/v1/rest/contact?*' => Http::response([
            ['id' => 'contact_jane', 'email' => 'jane@example.com', 'lists' => []],
        ], 200),
        'api.sendx.io/api/v1/rest/contact/contact_jane' => Http::response(['status' => 'error', 'message' => 'Invalid identifier format'], 400),
    ]);

    $result = sendX()->pushContact('list_bad', new ContactPayload(email: 'jane@example.com'));

    expect($result->successful)->toBeFalse()
        ->and($result->error)->toBe('Invalid identifier format')
        ->and($result->context['status'])->toBe(400);
});

test('pushContact reports a rejected address without looking it up', function () {
    Http::fake(['api.sendx.io/api/v1/rest/contact' => Http::response([
        'status' => 'error',
        'message' => 'invalid email format: must be a valid email address',
    ], 400)]);

    $result = sendX()->pushContact('list_a', new ContactPayload(email: 'not-an-email'));

    expect($result->successful)->toBeFalse()
        ->and($result->error)->toBe('invalid email format: must be a valid email address')
        ->and($result->context['status'])->toBe(400);

    Http::assertSentCount(1);
});

test('pushContact reports a throttled push with its status so the job can retry it', function () {
    Http::fake(['api.sendx.io/api/v1/rest/contact' => Http::response(['status' => 'error', 'message' => 'Rate limit exceeded'], 429)]);

    $result = sendX()->pushContact('list_a', new ContactPayload(email: 'jane@example.com'));

    expect($result->successful)->toBeFalse()
        ->and($result->context['status'])->toBe(429);
});
