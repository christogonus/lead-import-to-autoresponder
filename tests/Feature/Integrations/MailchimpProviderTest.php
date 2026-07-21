<?php

use App\Integrations\Drivers\MailchimpProvider;
use App\Integrations\Support\ContactPayload;
use Illuminate\Support\Facades\Http;

function mailchimp(array $credentials = ['api_key' => 'test-key-us21']): MailchimpProvider
{
    return new MailchimpProvider($credentials);
}

test('verify returns true for valid credentials', function () {
    Http::fake(['us21.api.mailchimp.com/3.0/ping' => Http::response(['health_status' => "Everything's Chimpy!"], 200)]);

    expect(mailchimp()->verify())->toBeTrue();

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization')
        && str_starts_with($request->header('Authorization')[0], 'Basic '));
});

test('verify returns false for rejected credentials', function () {
    Http::fake(['us21.api.mailchimp.com/3.0/ping' => Http::response(['detail' => 'API Key Invalid'], 401)]);

    expect(mailchimp(['api_key' => 'bad-us21'])->verify())->toBeFalse();
});

test('the datacenter suffix of the key selects the host', function () {
    Http::fake(['us5.api.mailchimp.com/3.0/ping' => Http::response([], 200)]);

    expect(mailchimp(['api_key' => 'abc-us5'])->verify())->toBeTrue();

    Http::assertSent(fn ($request) => $request->url() === 'https://us5.api.mailchimp.com/3.0/ping');
});

test('lists maps audiences to remote lists', function () {
    Http::fake(['us21.api.mailchimp.com/3.0/lists*' => Http::response([
        'lists' => [
            ['id' => 'a1', 'name' => 'Newsletter'],
            ['id' => 'b2', 'name' => 'Customers'],
        ],
    ], 200)]);

    $lists = mailchimp()->lists();

    expect($lists)->toHaveCount(2)
        ->and($lists[0]->id)->toBe('a1')
        ->and($lists[0]->name)->toBe('Newsletter')
        ->and($lists[1]->name)->toBe('Customers');
});

test('pushContact upserts the member by subscriber hash', function () {
    $hash = md5('jane@example.com');

    Http::fake(["us21.api.mailchimp.com/3.0/lists/abc/members/{$hash}" => Http::response(['id' => $hash], 200)]);

    $result = mailchimp()->pushContact('abc', new ContactPayload(
        email: 'jane@example.com',
        firstName: 'Jane',
        lastName: 'Doe',
        phone: '555-1234',
    ));

    expect($result->successful)->toBeTrue()
        ->and($result->remoteId)->toBe($hash);

    Http::assertSent(fn ($request) => $request->method() === 'PUT'
        && $request->url() === "https://us21.api.mailchimp.com/3.0/lists/abc/members/{$hash}"
        && $request['email_address'] === 'jane@example.com'
        && $request['status_if_new'] === 'subscribed'
        && $request['merge_fields']['FNAME'] === 'Jane'
        && $request['merge_fields']['LNAME'] === 'Doe'
        && $request['merge_fields']['PHONE'] === '555-1234');
});

test('pushContact lowercases the email for the subscriber hash', function () {
    $hash = md5('jane@example.com');

    Http::fake(["us21.api.mailchimp.com/3.0/lists/abc/members/{$hash}" => Http::response(['id' => $hash], 200)]);

    $result = mailchimp()->pushContact('abc', new ContactPayload(email: 'JANE@example.com'));

    expect($result->successful)->toBeTrue();

    Http::assertSent(fn ($request) => $request->url() === "https://us21.api.mailchimp.com/3.0/lists/abc/members/{$hash}");
});

test('pushContact omits blank merge fields', function () {
    $hash = md5('bare@example.com');

    Http::fake(["us21.api.mailchimp.com/3.0/lists/abc/members/{$hash}" => Http::response(['id' => $hash], 200)]);

    mailchimp()->pushContact('abc', new ContactPayload(email: 'bare@example.com'));

    Http::assertSent(fn ($request) => $request['merge_fields'] === []);
});

test('pushContact returns a failure with the provider error detail', function () {
    $hash = md5('nope@example.com');

    Http::fake(["us21.api.mailchimp.com/3.0/lists/abc/members/{$hash}" => Http::response(['detail' => 'Invalid resource'], 400)]);

    $result = mailchimp()->pushContact('abc', new ContactPayload(email: 'nope@example.com'));

    expect($result->successful)->toBeFalse()
        ->and($result->error)->toBe('Invalid resource');
});
