<?php

use App\Integrations\Drivers\ZohoCampaignsProvider;
use App\Integrations\Exceptions\IntegrationException;
use App\Integrations\Support\ContactPayload;
use Illuminate\Support\Facades\Http;

function zohoCampaigns(array $credentials = ['access_token' => 'test-token']): ZohoCampaignsProvider
{
    return new ZohoCampaignsProvider($credentials);
}

test('verify returns true for valid credentials', function () {
    Http::fake(['campaigns.zoho.com/api/v1.1/getmailinglists*' => Http::response([
        'status' => 'success',
        'code' => '0',
        'list_of_details' => [],
    ], 200)]);

    expect(zohoCampaigns()->verify())->toBeTrue();

    // Zoho requires its own auth scheme rather than Bearer.
    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Zoho-oauthtoken test-token'));
});

test('verify returns false when zoho reports an error inside a 200 response', function () {
    Http::fake(['campaigns.zoho.com/api/v1.1/getmailinglists*' => Http::response([
        'status' => 'error',
        'code' => '1007',
        'message' => 'Unauthorized key.',
    ], 200)]);

    expect(zohoCampaigns()->verify())->toBeFalse();
});

test('lists maps mailing lists by listkey', function () {
    Http::fake(['campaigns.zoho.com/api/v1.1/getmailinglists*' => Http::response([
        'status' => 'success',
        'code' => '0',
        'list_of_details' => [
            ['listkey' => '3c20ad524dfa4af86216a5be13e238ed', 'listname' => 'Newsletter'],
            ['listkey' => 'aa11bb22cc33dd44ee55ff6677889900', 'listname' => 'Webinar leads'],
        ],
    ], 200)]);

    $lists = zohoCampaigns()->lists();

    expect($lists)->toHaveCount(2)
        ->and($lists[0]->id)->toBe('3c20ad524dfa4af86216a5be13e238ed')
        ->and($lists[0]->name)->toBe('Newsletter')
        ->and($lists[1]->name)->toBe('Webinar leads');
});

test('lists pages until a short page is returned', function () {
    $full = array_map(
        fn (int $i): array => ['listkey' => "key-{$i}", 'listname' => "List {$i}"],
        range(1, 100),
    );

    Http::fake(['campaigns.zoho.com/api/v1.1/getmailinglists*' => Http::sequence()
        ->push(['status' => 'success', 'code' => '0', 'list_of_details' => $full], 200)
        ->push(['status' => 'success', 'code' => '0', 'list_of_details' => [
            ['listkey' => 'key-101', 'listname' => 'List 101'],
        ]], 200)]);

    expect(zohoCampaigns()->lists())->toHaveCount(101);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'fromindex=101'));
});

test('lists throws when the request is rejected', function () {
    Http::fake(['campaigns.zoho.com/api/v1.1/getmailinglists*' => Http::response([
        'status' => 'error',
        'code' => '2701',
        'message' => 'Insufficient privilege to access your mailing list.',
    ], 200)]);

    zohoCampaigns()->lists();
})->throws(IntegrationException::class, 'Insufficient privilege to access your mailing list. (code 2701)');

test('pushContact subscribes the contact with a json-encoded contactinfo string', function () {
    Http::fake(['campaigns.zoho.com/api/v1.1/json/listsubscribe*' => Http::response([
        'status' => 'success',
        'code' => '0',
        'message' => 'Contact added successfully.',
    ], 200)]);

    $result = zohoCampaigns()->pushContact('list-key-1', new ContactPayload(
        email: 'jane@example.com',
        firstName: 'Jane',
        lastName: 'Doe',
        phone: '555-1234',
    ));

    expect($result->successful)->toBeTrue();

    Http::assertSent(function ($request) {
        $info = json_decode($request['contactinfo'], true);

        return $request->method() === 'POST'
            && $request['listkey'] === 'list-key-1'
            && $request['resfmt'] === 'JSON'
            && $info === [
                'Contact Email' => 'jane@example.com',
                'First Name' => 'Jane',
                'Last Name' => 'Doe',
            ];
    });
});

test('pushContact omits blank name fields', function () {
    Http::fake(['campaigns.zoho.com/api/v1.1/json/listsubscribe*' => Http::response([
        'status' => 'success',
        'code' => '0',
    ], 200)]);

    zohoCampaigns()->pushContact('list-key-1', new ContactPayload(email: 'bare@example.com'));

    Http::assertSent(fn ($request) => json_decode($request['contactinfo'], true) === [
        'Contact Email' => 'bare@example.com',
    ]);
});

test('pushContact treats an already-subscribed contact as a success', function () {
    Http::fake(['campaigns.zoho.com/api/v1.1/json/listsubscribe*' => Http::response([
        'status' => 'error',
        'code' => '2003',
        'message' => 'Contact exists in this list.',
    ], 200)]);

    $result = zohoCampaigns()->pushContact('list-key-1', new ContactPayload(email: 'dupe@example.com'));

    expect($result->successful)->toBeTrue();
});

test('pushContact returns a failure for an invalid email address', function () {
    Http::fake(['campaigns.zoho.com/api/v1.1/json/listsubscribe*' => Http::response([
        'status' => 'error',
        'code' => '2004',
        'message' => 'Invalid contact email address.',
    ], 200)]);

    $result = zohoCampaigns()->pushContact('list-key-1', new ContactPayload(email: 'nope'));

    expect($result->successful)->toBeFalse()
        ->and($result->error)->toBe('Invalid contact email address. (code 2004)');
});

test('the api domain captured at connection time pins the data centre', function () {
    Http::fake(['campaigns.zoho.eu/api/v1.1/json/listsubscribe*' => Http::response([
        'status' => 'success',
        'code' => '0',
    ], 200)]);

    $provider = zohoCampaigns(['access_token' => 'test-token', 'api_domain' => 'https://www.zohoapis.eu']);

    expect($provider->pushContact('key', new ContactPayload(email: 'jane@example.com'))->successful)->toBeTrue();

    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://campaigns.zoho.eu/'));
});

test('the configured region is used when no api domain was stored', function () {
    config(['services.zoho_campaigns.region' => 'com.au']);

    Http::fake(['campaigns.zoho.com.au/api/v1.1/getmailinglists*' => Http::response([
        'status' => 'success',
        'code' => '0',
        'list_of_details' => [],
    ], 200)]);

    expect(zohoCampaigns()->verify())->toBeTrue();
});
