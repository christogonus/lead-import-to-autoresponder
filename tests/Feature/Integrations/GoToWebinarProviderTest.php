<?php

use App\Integrations\Drivers\GoToWebinarProvider;
use App\Integrations\Support\ContactPayload;
use Illuminate\Support\Facades\Http;

function goToWebinar(array $credentials = ['access_token' => 'test-token', 'organizer_key' => '999']): GoToWebinarProvider
{
    return new GoToWebinarProvider($credentials);
}

test('verify returns true for a valid token', function () {
    Http::fake(['api.getgo.com/G2W/rest/v2/organizers/999/webinars' => Http::response([], 200)]);

    expect(goToWebinar()->verify())->toBeTrue();

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer test-token'));
});

test('verify returns false when the token is rejected', function () {
    Http::fake(['api.getgo.com/G2W/rest/v2/organizers/999/webinars' => Http::response(['description' => 'Unauthorized'], 401)]);

    expect(goToWebinar()->verify())->toBeFalse();
});

test('verify returns false without an organizer key and makes no request', function () {
    Http::fake();

    expect(goToWebinar(['access_token' => 'test-token'])->verify())->toBeFalse();

    Http::assertNothingSent();
});

test('lists maps webinars returned as a plain array', function () {
    Http::fake(['api.getgo.com/G2W/rest/v2/organizers/999/webinars' => Http::response([
        ['webinarKey' => 111, 'subject' => 'Launch Webinar'],
        ['webinarKey' => 222, 'subject' => 'Demo Day'],
    ], 200)]);

    $lists = goToWebinar()->lists();

    expect($lists)->toHaveCount(2)
        ->and($lists[0]->id)->toBe('111')
        ->and($lists[0]->name)->toBe('Launch Webinar')
        ->and($lists[1]->id)->toBe('222');
});

test('lists also handles a HAL embedded envelope', function () {
    Http::fake(['api.getgo.com/G2W/rest/v2/organizers/999/webinars' => Http::response([
        '_embedded' => ['webinars' => [['webinarKey' => 333, 'subject' => 'Quarterly Update']]],
    ], 200)]);

    $lists = goToWebinar()->lists();

    expect($lists)->toHaveCount(1)
        ->and($lists[0]->id)->toBe('333')
        ->and($lists[0]->name)->toBe('Quarterly Update');
});

test('pushContact registers the contact and returns the registrant key', function () {
    Http::fake(['api.getgo.com/G2W/rest/v2/organizers/999/webinars/555/registrants' => Http::response([
        'registrantKey' => 12345,
        'joinUrl' => 'https://global.gotowebinar.com/join/12345',
    ], 201)]);

    $result = goToWebinar()->pushContact('555', new ContactPayload(
        email: 'jane@example.com',
        firstName: 'Jane',
        lastName: 'Doe',
    ));

    expect($result->successful)->toBeTrue()
        ->and($result->remoteId)->toBe('12345');

    Http::assertSent(fn ($request) => $request->url() === 'https://api.getgo.com/G2W/rest/v2/organizers/999/webinars/555/registrants'
        && $request->method() === 'POST'
        && $request['firstName'] === 'Jane'
        && $request['lastName'] === 'Doe'
        && $request['email'] === 'jane@example.com');
});

test('pushContact derives a name from the email for a contact with only the placeholder name', function () {
    Http::fake(['api.getgo.com/G2W/rest/v2/organizers/999/webinars/555/registrants' => Http::response([
        'registrantKey' => 12345,
    ], 201)]);

    $result = goToWebinar()->pushContact('555', new ContactPayload(
        email: 'grace.hopper@example.com',
        firstName: 'there',
        lastName: null,
    ));

    expect($result->successful)->toBeTrue();

    // The "there" greeting placeholder is not a real name, so it is replaced
    // rather than registered verbatim.
    Http::assertSent(fn ($request) => $request['firstName'] === 'Grace'
        && $request['lastName'] === 'Hopper');
});

test('pushContact falls back to the placeholder for a last name it cannot derive', function () {
    Http::fake(['api.getgo.com/G2W/rest/v2/organizers/999/webinars/555/registrants' => Http::response([
        'registrantKey' => 12345,
    ], 201)]);

    goToWebinar()->pushContact('555', new ContactPayload(email: 'jane@example.com', firstName: 'there'));

    Http::assertSent(fn ($request) => $request['firstName'] === 'Jane'
        && $request['lastName'] === 'Subscriber');
});

test('pushContact falls back entirely for an email with nothing name-like in it', function () {
    Http::fake(['api.getgo.com/G2W/rest/v2/organizers/999/webinars/555/registrants' => Http::response([
        'registrantKey' => 12345,
    ], 201)]);

    goToWebinar()->pushContact('555', new ContactPayload(email: '12345@example.com', firstName: 'there'));

    Http::assertSent(fn ($request) => $request['firstName'] === 'Subscriber'
        && $request['lastName'] === 'Subscriber');
});

test('pushContact keeps a real name rather than deriving one', function () {
    Http::fake(['api.getgo.com/G2W/rest/v2/organizers/999/webinars/555/registrants' => Http::response([
        'registrantKey' => 12345,
    ], 201)]);

    goToWebinar()->pushContact('555', new ContactPayload(
        email: 'grace.hopper@example.com',
        firstName: 'Jane',
        lastName: null,
    ));

    Http::assertSent(fn ($request) => $request['firstName'] === 'Jane'
        && $request['lastName'] === 'Subscriber');
});

test('pushContact treats an already-registered contact (409) as success', function () {
    Http::fake(['api.getgo.com/G2W/rest/v2/organizers/999/webinars/555/registrants' => Http::response([
        'registrantKey' => 777,
        'description' => 'You have already registered for this webinar',
    ], 409)]);

    $result = goToWebinar()->pushContact('555', new ContactPayload(
        email: 'existing@example.com',
        firstName: 'Ann',
        lastName: 'Lee',
    ));

    expect($result->successful)->toBeTrue()
        ->and($result->remoteId)->toBe('777');
});

test('pushContact returns a failure for other errors', function () {
    Http::fake(['api.getgo.com/G2W/rest/v2/organizers/999/webinars/555/registrants' => Http::response([
        'description' => 'The field firstName is required.',
    ], 400)]);

    $result = goToWebinar()->pushContact('555', new ContactPayload(email: 'nameless@example.com'));

    expect($result->successful)->toBeFalse()
        ->and($result->error)->toBe('The field firstName is required.');
});

test('pushContact fails cleanly without an organizer key', function () {
    Http::fake();

    $result = goToWebinar(['access_token' => 'test-token'])->pushContact('555', new ContactPayload(email: 'jane@example.com'));

    expect($result->successful)->toBeFalse();

    Http::assertNothingSent();
});
