<?php

use App\Enums\ContactStatus;
use App\Jobs\PushDeliveryContact;
use App\Models\Contact;
use App\Models\ContactList;
use App\Models\Delivery;
use App\Models\DeliveryContact;
use App\Models\Integration;
use App\Models\Team;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

function contactAwaitingPush(string $provider = 'getresponse', string $email = 'jane@example.com'): DeliveryContact
{
    $team = Team::factory()->create();
    $integration = Integration::factory()->create(['team_id' => $team->id, 'provider' => $provider]);
    $list = ContactList::factory()->create(['team_id' => $team->id]);
    $contact = Contact::factory()->create([
        'team_id' => $team->id,
        'contact_list_id' => $list->id,
        'email' => $email,
    ]);
    $delivery = Delivery::factory()->create([
        'team_id' => $team->id,
        'contact_list_id' => $list->id,
        'integration_id' => $integration->id,
        'remote_id' => 'camp123',
        'status' => Delivery::STATUS_PROCESSING,
        'total_count' => 1,
    ]);

    return DeliveryContact::factory()->create([
        'delivery_id' => $delivery->id,
        'contact_id' => $contact->id,
        'status' => ContactStatus::Pending,
    ]);
}

test('a rejected push is logged with the response status and body', function () {
    Http::fake(['api.getresponse.com/v3/contacts' => Http::response([
        'message' => 'Invalid email address',
        'context' => ['field' => 'email'],
    ], 400)]);

    Log::shouldReceive('error')
        ->once()
        ->withArgs(function (string $message, array $context): bool {
            expect($message)->toBe('Autoresponder contact push failed.')
                ->and($context['provider'])->toBe('getresponse')
                ->and($context['email'])->toBe('jane@example.com')
                ->and($context['remote_list_id'])->toBe('camp123')
                ->and($context['error'])->toBe('Invalid email address')
                ->and($context['response']['status'])->toBe(400)
                // The raw body carries the detail the collapsed message drops.
                ->and($context['response']['body'])->toContain('"field":"email"');

            return true;
        });

    PushDeliveryContact::dispatchSync(contactAwaitingPush());
});

test('the log identifies which contact and delivery failed', function () {
    Http::fake(['api.getresponse.com/v3/contacts' => Http::response(['message' => 'Nope'], 400)]);

    $deliveryContact = contactAwaitingPush(email: 'stuck@example.com');

    Log::shouldReceive('error')
        ->once()
        ->withArgs(function (string $message, array $context) use ($deliveryContact): bool {
            expect($context['delivery_contact_id'])->toBe($deliveryContact->id)
                ->and($context['delivery_id'])->toBe($deliveryContact->delivery_id)
                ->and($context['contact_id'])->toBe($deliveryContact->contact_id)
                ->and($context['email'])->toBe('stuck@example.com');

            return true;
        });

    PushDeliveryContact::dispatchSync($deliveryContact);
});

test('a successful push logs nothing', function () {
    Http::fake(['api.getresponse.com/v3/*' => Http::response(null, 202)]);

    Log::shouldReceive('error')->never();

    PushDeliveryContact::dispatchSync(contactAwaitingPush());
});

test('a push for a cancelled delivery logs nothing', function () {
    Http::fake(['api.getresponse.com/v3/*' => Http::response(null, 202)]);

    $deliveryContact = contactAwaitingPush();
    $deliveryContact->delivery->cancel();

    Log::shouldReceive('error')->never();

    PushDeliveryContact::dispatchSync($deliveryContact);
});

test('a failure still records the error against the contact for the UI', function () {
    Http::fake(['api.getresponse.com/v3/contacts' => Http::response(['message' => 'Invalid email address'], 400)]);

    $deliveryContact = contactAwaitingPush();

    PushDeliveryContact::dispatchSync($deliveryContact);

    expect($deliveryContact->fresh()->status)->toBe(ContactStatus::Failed)
        ->and($deliveryContact->fresh()->sync_error)->toBe('Invalid email address');
});
