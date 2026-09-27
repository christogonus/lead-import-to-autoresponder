<?php

use App\Integrations\Drivers\BeehiivProvider;
use App\Integrations\Exceptions\IntegrationException;
use App\Integrations\Support\ContactPayload;
use App\Models\Contact;
use Illuminate\Support\Facades\Http;

function beehiiv(array $credentials = ['api_key' => 'test-key']): BeehiivProvider
{
    return new BeehiivProvider($credentials);
}

/**
 * A page of the publications listing in beehiiv's response shape.
 *
 * @param  array<int, array{id: string, name: string}>  $publications
 */
function beehiivPublicationsPage(array $publications, int $page, int $totalPages): array
{
    return [
        'data' => $publications,
        'limit' => 100,
        'page' => $page,
        'total_results' => count($publications),
        'total_pages' => $totalPages,
    ];
}

test('verify returns true for valid credentials', function () {
    Http::fake(['api.beehiiv.com/v2/publications*' => Http::response(beehiivPublicationsPage([], 1, 1), 200)]);

    expect(beehiiv()->verify())->toBeTrue();

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer test-key'));
});

test('verify returns false for rejected credentials', function () {
    Http::fake(['api.beehiiv.com/v2/publications*' => Http::response([
        'status' => 401,
        'statusText' => 'Unauthorized',
        'errors' => [['message' => 'Invalid API key', 'code' => 'unauthorized']],
    ], 401)]);

    expect(beehiiv(['api_key' => 'bad'])->verify())->toBeFalse();
});

test('lists maps publications across pages', function () {
    Http::fake(['api.beehiiv.com/v2/publications*' => Http::sequence()
        ->push(beehiivPublicationsPage([['id' => 'pub_one', 'name' => 'Morning Brew']], 1, 2), 200)
        ->push(beehiivPublicationsPage([['id' => 'pub_two', 'name' => 'Weekly Digest']], 2, 2), 200)]);

    $lists = beehiiv()->lists();

    expect($lists)->toHaveCount(2)
        ->and($lists[0]->id)->toBe('pub_one')
        ->and($lists[0]->name)->toBe('Morning Brew')
        ->and($lists[1]->id)->toBe('pub_two');

    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => str_contains($request->url(), 'page=2'));
});

test('lists throws when the request is rejected', function () {
    Http::fake(['api.beehiiv.com/v2/publications*' => Http::response([
        'errors' => [['message' => 'Invalid API key', 'code' => 'unauthorized']],
    ], 401)]);

    expect(fn () => beehiiv()->lists())
        ->toThrow(IntegrationException::class, 'Invalid API key');
});

test('pushContact subscribes the contact to the publication with its details as custom fields', function () {
    Http::fake(['api.beehiiv.com/v2/publications/pub_one/subscriptions' => Http::response([
        'data' => ['id' => 'sub_123', 'email' => 'jane@example.com', 'status' => 'active'],
    ], 200)]);

    $result = beehiiv()->pushContact('pub_one', new ContactPayload(
        email: 'jane@example.com',
        firstName: 'Jane',
        lastName: 'Doe',
        phone: '+380632727700',
        country: 'UA',
    ));

    expect($result->successful)->toBeTrue()
        ->and($result->remoteId)->toBe('sub_123');

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && $request->url() === 'https://api.beehiiv.com/v2/publications/pub_one/subscriptions'
        && $request->data() === [
            'email' => 'jane@example.com',
            'reactivate_existing' => false,
            'send_welcome_email' => true,
            'custom_fields' => [
                ['name' => 'First Name', 'value' => 'Jane'],
                ['name' => 'Last Name', 'value' => 'Doe'],
                ['name' => 'Phone', 'value' => '+380632727700'],
                ['name' => 'Country', 'value' => 'UA'],
            ],
        ]);
});

test('pushContact omits custom fields the contact does not carry, including the greeting placeholder', function () {
    Http::fake(['api.beehiiv.com/v2/publications/pub_one/subscriptions' => Http::response(['data' => ['id' => 'sub_123']], 200)]);

    beehiiv()->pushContact('pub_one', new ContactPayload(
        email: 'jane@example.com',
        firstName: Contact::DEFAULT_FIRST_NAME,
    ));

    Http::assertSent(fn ($request) => $request->data() === [
        'email' => 'jane@example.com',
        'reactivate_existing' => false,
        'send_welcome_email' => true,
    ]);
});

test('pushContact reports a rejected push with its message and status', function () {
    Http::fake(['api.beehiiv.com/v2/publications/pub_one/subscriptions' => Http::response([
        'status' => 400,
        'statusText' => 'Bad Request',
        'errors' => [['message' => 'Email is invalid', 'code' => 'invalid_email']],
    ], 400)]);

    $result = beehiiv()->pushContact('pub_one', new ContactPayload(email: 'not-an-email'));

    expect($result->successful)->toBeFalse()
        ->and($result->error)->toBe('Email is invalid')
        ->and($result->context['status'])->toBe(400);
});

test('pushContact reports a throttled push with its status so the job can retry it', function () {
    Http::fake(['api.beehiiv.com/v2/publications/pub_one/subscriptions' => Http::response([], 429)]);

    $result = beehiiv()->pushContact('pub_one', new ContactPayload(email: 'jane@example.com'));

    expect($result->successful)->toBeFalse()
        ->and($result->error)->toBe('HTTP 429')
        ->and($result->context['status'])->toBe(429);
});
