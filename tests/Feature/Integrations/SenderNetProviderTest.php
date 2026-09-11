<?php

use App\Integrations\Drivers\SenderNetProvider;
use App\Integrations\Exceptions\IntegrationException;
use App\Integrations\Support\ContactPayload;
use Illuminate\Support\Facades\Http;

function senderNet(array $credentials = ['api_key' => 'test-token']): SenderNetProvider
{
    return new SenderNetProvider($credentials);
}

test('verify returns true for valid credentials', function () {
    Http::fake(['api.sender.net/v2/groups*' => Http::response(['data' => [], 'meta' => ['last_page' => 1]], 200)]);

    expect(senderNet()->verify())->toBeTrue();

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer test-token'));
});

test('verify returns false for rejected credentials', function () {
    Http::fake(['api.sender.net/v2/groups*' => Http::response(['success' => false, 'message' => 'Unauthenticated.'], 401)]);

    expect(senderNet(['api_key' => 'bad'])->verify())->toBeFalse();
});

test('lists maps groups across pages', function () {
    Http::fake(['api.sender.net/v2/groups*' => Http::sequence()
        ->push(['data' => [
            ['id' => 'eZVD4w', 'title' => 'Newsletter'],
            ['id' => 'aB12cd', 'title' => 'Customers'],
        ], 'meta' => ['current_page' => 1, 'last_page' => 2]], 200)
        ->push(['data' => [
            ['id' => 'zZ99yy', 'title' => 'VIP'],
        ], 'meta' => ['current_page' => 2, 'last_page' => 2]], 200)]);

    $lists = senderNet()->lists();

    expect($lists)->toHaveCount(3)
        ->and($lists[0]->id)->toBe('eZVD4w')
        ->and($lists[0]->name)->toBe('Newsletter')
        ->and($lists[2]->id)->toBe('zZ99yy')
        ->and($lists[2]->name)->toBe('VIP');
});

test('lists falls back to the id when a group carries no title', function () {
    Http::fake(['api.sender.net/v2/groups*' => Http::response([
        'data' => [['id' => 'eZVD4w']],
        'meta' => ['last_page' => 1],
    ], 200)]);

    expect(senderNet()->lists()[0]->name)->toBe('eZVD4w');
});

test('lists throws when the request is rejected', function () {
    Http::fake(['api.sender.net/v2/groups*' => Http::response(['message' => 'Unauthenticated.'], 401)]);

    expect(fn () => senderNet()->lists())
        ->toThrow(IntegrationException::class, 'Unauthenticated.');
});

test('pushContact creates the subscriber straight into the group', function () {
    Http::fake(['api.sender.net/v2/subscribers' => Http::response([
        'success' => true,
        'data' => ['id' => 'o2lk68Y', 'email' => 'jane@example.com'],
    ], 200)]);

    $result = senderNet()->pushContact('eZVD4w', new ContactPayload(
        email: 'jane@example.com',
        firstName: 'Jane',
        lastName: 'Doe',
    ));

    expect($result->successful)->toBeTrue()
        ->and($result->remoteId)->toBe('o2lk68Y');

    Http::assertSent(fn ($request) => $request->url() === 'https://api.sender.net/v2/subscribers'
        && $request['email'] === 'jane@example.com'
        && $request['firstname'] === 'Jane'
        && $request['lastname'] === 'Doe'
        && $request['groups'] === ['eZVD4w']);
});

test('pushContact omits names the contact does not carry', function () {
    Http::fake(['api.sender.net/v2/subscribers' => Http::response(['data' => ['id' => 'o2lk68Y']], 200)]);

    senderNet()->pushContact('eZVD4w', new ContactPayload(email: 'jane@example.com'));

    Http::assertSent(fn ($request) => ! array_key_exists('firstname', $request->data())
        && ! array_key_exists('lastname', $request->data())
        && ! array_key_exists('phone', $request->data()));
});

test('pushContact sends a phone number only in the international form Sender accepts', function (?string $phone, ?string $sent) {
    Http::fake(['api.sender.net/v2/subscribers' => Http::response(['data' => ['id' => 'o2lk68Y']], 200)]);

    senderNet()->pushContact('eZVD4w', new ContactPayload(email: 'jane@example.com', phone: $phone));

    Http::assertSent(fn ($request) => ($request->data()['phone'] ?? null) === $sent);
})->with([
    ['+370 600 12345', '+37060012345'],
    ['0037060012345', '0037060012345'],
    ['(555) 123-4567', null],
    ['555-1234', null],
    [null, null],
]);

test('pushContact adds an address Sender already holds to the group', function () {
    Http::fake([
        'api.sender.net/v2/subscribers' => Http::response([
            'success' => false,
            'message' => 'The given data was invalid.',
            'errors' => ['email' => ['The email has already been taken.']],
        ], 422),
        'api.sender.net/v2/subscribers/groups/eZVD4w' => Http::response([
            'success' => true,
            'message' => [
                'subscribers_added_to_group' => ['Jane@example.com'],
                'non_existing_subscribers' => [],
            ],
        ], 200),
        'api.sender.net/v2/subscribers/jane%40example.com' => Http::response([
            'data' => ['id' => 'o2lk68Y'],
        ], 200),
    ]);

    $result = senderNet()->pushContact('eZVD4w', new ContactPayload(email: 'jane@example.com'));

    expect($result->successful)->toBeTrue()
        ->and($result->remoteId)->toBe('o2lk68Y');

    Http::assertSent(fn ($request) => $request->url() === 'https://api.sender.net/v2/subscribers/groups/eZVD4w'
        && $request['subscribers'] === ['jane@example.com']);
});

test('pushContact still succeeds when the existing subscriber id cannot be read', function () {
    Http::fake([
        'api.sender.net/v2/subscribers' => Http::response(['message' => 'The email has already been taken.'], 422),
        'api.sender.net/v2/subscribers/groups/eZVD4w' => Http::response([
            'message' => ['subscribers_added_to_group' => ['jane@example.com']],
        ], 200),
        'api.sender.net/v2/subscribers/jane%40example.com' => Http::response(['message' => 'Not found.'], 404),
    ]);

    $result = senderNet()->pushContact('eZVD4w', new ContactPayload(email: 'jane@example.com'));

    expect($result->successful)->toBeTrue()
        ->and($result->remoteId)->toBeNull();
});

test('pushContact reports the original rejection when Sender will not take the address at all', function () {
    Http::fake([
        'api.sender.net/v2/subscribers' => Http::response([
            'message' => 'The given data was invalid.',
            'errors' => ['email' => ['The email must be a valid email address.']],
        ], 422),
        'api.sender.net/v2/subscribers/groups/eZVD4w' => Http::response([
            'message' => [
                'subscribers_added_to_group' => [],
                'non_existing_subscribers' => ['not-an-email'],
            ],
        ], 200),
    ]);

    $result = senderNet()->pushContact('eZVD4w', new ContactPayload(email: 'not-an-email'));

    expect($result->successful)->toBeFalse()
        ->and($result->error)->toBe('The email must be a valid email address.')
        ->and($result->context['status'])->toBe(422);
});

test('pushContact reports a throttled push with its status so the job can retry it', function () {
    Http::fake([
        'api.sender.net/v2/subscribers' => Http::response(['message' => 'Too Many Attempts.'], 429),
        'api.sender.net/v2/subscribers/groups/eZVD4w' => Http::response(['message' => 'Too Many Attempts.'], 429),
    ]);

    $result = senderNet()->pushContact('eZVD4w', new ContactPayload(email: 'jane@example.com'));

    expect($result->successful)->toBeFalse()
        ->and($result->error)->toBe('Too Many Attempts.')
        ->and($result->context['status'])->toBe(429);
});
