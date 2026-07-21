<?php

use App\Integrations\Drivers\GetResponseProvider;
use App\Integrations\Support\ContactPayload;
use Illuminate\Support\Facades\Http;

function getResponse(array $credentials = ['api_key' => 'test-key']): GetResponseProvider
{
    return new GetResponseProvider($credentials);
}

test('verify returns true for valid credentials', function () {
    Http::fake(['api.getresponse.com/v3/accounts' => Http::response([], 200)]);

    expect(getResponse()->verify())->toBeTrue();

    Http::assertSent(fn ($request) => $request->hasHeader('X-Auth-Token', 'api-key test-key'));
});

test('verify returns false for rejected credentials', function () {
    Http::fake(['api.getresponse.com/v3/accounts' => Http::response(['message' => 'Unauthorized'], 401)]);

    expect(getResponse(['api_key' => 'bad'])->verify())->toBeFalse();
});

test('lists maps campaigns to remote lists', function () {
    Http::fake(['api.getresponse.com/v3/campaigns*' => Http::response([
        ['campaignId' => 'abc123', 'name' => 'Newsletter'],
        ['campaignId' => 'def456', 'name' => 'Promotions'],
    ], 200)]);

    $lists = getResponse()->lists();

    expect($lists)->toHaveCount(2)
        ->and($lists[0]->id)->toBe('abc123')
        ->and($lists[0]->name)->toBe('Newsletter');
});

test('pushContact posts the contact with a combined name', function () {
    Http::fake(['api.getresponse.com/v3/contacts' => Http::response(null, 202)]);

    $result = getResponse()->pushContact('camp123', new ContactPayload(
        email: 'jane@example.com',
        firstName: 'Jane',
        lastName: 'Doe',
    ));

    expect($result->successful)->toBeTrue();

    Http::assertSent(fn ($request) => $request->url() === 'https://api.getresponse.com/v3/contacts'
        && $request['email'] === 'jane@example.com'
        && $request['name'] === 'Jane Doe'
        && $request['campaign']['campaignId'] === 'camp123');
});

test('pushContact maps phone and country onto custom fields', function () {
    Http::fake([
        'api.getresponse.com/v3/custom-fields*' => Http::response([
            ['customFieldId' => 'cf_phone', 'name' => 'phone'],
            ['customFieldId' => 'cf_country', 'name' => 'country'],
        ], 200),
        'api.getresponse.com/v3/contacts' => Http::response(null, 202),
    ]);

    getResponse()->pushContact('camp123', new ContactPayload(
        email: 'jane@example.com',
        phone: '555-1234',
        country: 'United States',
    ));

    Http::assertSent(function ($request) {
        if ($request->url() !== 'https://api.getresponse.com/v3/contacts') {
            return false;
        }

        $fields = collect($request['customFieldValues']);

        return $fields->contains(fn ($f) => $f['customFieldId'] === 'cf_phone' && $f['value'] === ['555-1234'])
            && $fields->contains(fn ($f) => $f['customFieldId'] === 'cf_country' && $f['value'] === ['United States']);
    });
});

test('pushContact creates a custom field when it does not exist', function () {
    Http::fake([
        'api.getresponse.com/v3/custom-fields*' => Http::sequence()
            ->push([], 200)
            ->push(['customFieldId' => 'cf_new_phone', 'name' => 'phone'], 201),
        'api.getresponse.com/v3/contacts' => Http::response(null, 202),
    ]);

    getResponse()->pushContact('camp123', new ContactPayload(
        email: 'jane@example.com',
        phone: '555-1234',
    ));

    Http::assertSent(fn ($request) => $request->url() === 'https://api.getresponse.com/v3/custom-fields'
        && $request->method() === 'POST'
        && $request['name'] === 'phone');
});

test('pushContact returns a failure with the provider error message', function () {
    Http::fake(['api.getresponse.com/v3/contacts' => Http::response(['message' => 'Invalid email address'], 400)]);

    $result = getResponse()->pushContact('camp123', new ContactPayload(email: 'not-an-email'));

    expect($result->successful)->toBeFalse()
        ->and($result->error)->toBe('Invalid email address');
});
