<?php

use App\Integrations\Drivers\SendPulseProvider;
use App\Integrations\Exceptions\IntegrationException;
use App\Integrations\Support\ContactPayload;
use Illuminate\Support\Facades\Http;

function sendPulse(array $credentials = ['api_key' => 'test-key']): SendPulseProvider
{
    return new SendPulseProvider($credentials);
}

test('verify returns true for valid credentials', function () {
    Http::fake(['api.sendpulse.com/addressbooks*' => Http::response([], 200)]);

    expect(sendPulse()->verify())->toBeTrue();

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer test-key'));
});

test('verify returns false for rejected credentials', function () {
    Http::fake(['api.sendpulse.com/addressbooks*' => Http::response([
        'error' => 'invalid_client',
        'message' => 'Client authentication failed.',
    ], 401)]);

    expect(sendPulse(['api_key' => 'bad'])->verify())->toBeFalse();
});

test('lists maps mailing lists across pages', function () {
    $fullPage = collect(range(1, 100))
        ->map(fn (int $i): array => ['id' => $i, 'name' => "Book {$i}"])
        ->all();

    Http::fake(['api.sendpulse.com/addressbooks*' => Http::sequence()
        ->push($fullPage, 200)
        ->push([['id' => 1234, 'name' => 'Newsletter', 'all_email_qty' => 3]], 200)]);

    $lists = sendPulse()->lists();

    expect($lists)->toHaveCount(101)
        ->and($lists[0]->id)->toBe('1')
        ->and($lists[100]->id)->toBe('1234')
        ->and($lists[100]->name)->toBe('Newsletter');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'offset=100'));
});

test('lists throws when the request is rejected', function () {
    Http::fake(['api.sendpulse.com/addressbooks*' => Http::response([
        'error' => 'invalid_client',
        'message' => 'Client authentication failed.',
    ], 401)]);

    expect(fn () => sendPulse()->lists())
        ->toThrow(IntegrationException::class, 'Client authentication failed.');
});

test('pushContact adds the contact to the mailing list with its name and phone', function () {
    Http::fake(['api.sendpulse.com/addressbooks/1234/emails' => Http::response(['result' => true], 200)]);

    $result = sendPulse()->pushContact('1234', new ContactPayload(
        email: 'jane@example.com',
        firstName: 'Jane',
        lastName: 'Doe',
        phone: '380632727700',
        country: 'UA',
    ));

    expect($result->successful)->toBeTrue()
        ->and($result->remoteId)->toBeNull();

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && $request->url() === 'https://api.sendpulse.com/addressbooks/1234/emails'
        && $request->data() === ['emails' => [[
            'email' => 'jane@example.com',
            'variables' => ['name' => 'Jane Doe', 'Phone' => '380632727700'],
        ]]]);
});

test('pushContact omits variables the contact does not carry', function () {
    Http::fake(['api.sendpulse.com/addressbooks/1234/emails' => Http::response(['result' => true], 200)]);

    sendPulse()->pushContact('1234', new ContactPayload(email: 'jane@example.com'));

    Http::assertSent(fn ($request) => $request->data() === ['emails' => [['email' => 'jane@example.com']]]);
});

test('pushContact fails when sendpulse answers 200 without a result', function () {
    Http::fake(['api.sendpulse.com/addressbooks/1234/emails' => Http::response([
        'result' => false,
        'message' => 'Book not found',
    ], 200)]);

    $result = sendPulse()->pushContact('1234', new ContactPayload(email: 'jane@example.com'));

    expect($result->successful)->toBeFalse()
        ->and($result->error)->toBe('Book not found');
});

test('pushContact reports a rejected push with its status', function () {
    Http::fake(['api.sendpulse.com/addressbooks/1234/emails' => Http::response([
        'error_code' => 213,
        'message' => 'Invalid emails',
    ], 400)]);

    $result = sendPulse()->pushContact('1234', new ContactPayload(email: 'not-an-email'));

    expect($result->successful)->toBeFalse()
        ->and($result->error)->toBe('Invalid emails')
        ->and($result->context['status'])->toBe(400);
});

test('pushContact reports a throttled push with its status so the job can retry it', function () {
    Http::fake(['api.sendpulse.com/addressbooks/1234/emails' => Http::response(['message' => 'Too many requests'], 429)]);

    $result = sendPulse()->pushContact('1234', new ContactPayload(email: 'jane@example.com'));

    expect($result->successful)->toBeFalse()
        ->and($result->context['status'])->toBe(429);
});
