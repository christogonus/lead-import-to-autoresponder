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

function pendingDeliveryContact(): DeliveryContact
{
    $team = Team::factory()->create();
    $integration = Integration::factory()->create(['team_id' => $team->id]);
    $list = ContactList::factory()->create(['team_id' => $team->id]);
    $contact = Contact::factory()->create(['team_id' => $team->id, 'contact_list_id' => $list->id]);
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

test('a pending delivery contact is pushed and marked synced, and the delivery completes', function () {
    Http::fake(['api.getresponse.com/v3/*' => Http::response(null, 202)]);

    $deliveryContact = pendingDeliveryContact();

    PushDeliveryContact::dispatchSync($deliveryContact);

    expect($deliveryContact->fresh()->status)->toBe(ContactStatus::Synced)
        ->and($deliveryContact->fresh()->synced_at)->not->toBeNull();

    $delivery = $deliveryContact->delivery->fresh();
    expect($delivery->synced_count)->toBe(1)
        ->and($delivery->failed_count)->toBe(0)
        ->and($delivery->status)->toBe(Delivery::STATUS_COMPLETED);

    Http::assertSent(fn ($request) => $request->url() === 'https://api.getresponse.com/v3/contacts'
        && $request['campaign']['campaignId'] === 'camp123');
});

test('a throttled push is released for retry instead of being marked failed', function () {
    Http::fake(['api.getresponse.com/v3/contacts' => Http::response(
        ['message' => 'Too many requests on server. Please try again in a few seconds.'],
        429,
    )]);

    $deliveryContact = pendingDeliveryContact();

    PushDeliveryContact::dispatchSync($deliveryContact);

    // The contact is still waiting its turn, not recorded as a failure.
    expect($deliveryContact->fresh()->status)->toBe(ContactStatus::Pending)
        ->and($deliveryContact->fresh()->sync_error)->toBeNull();

    $delivery = $deliveryContact->delivery->fresh();
    expect($delivery->failed_count)->toBe(0)
        ->and($delivery->status)->toBe(Delivery::STATUS_PROCESSING);
});

test('a rejected delivery contact is marked failed with the error and counted', function () {
    Http::fake(['api.getresponse.com/v3/contacts' => Http::response(['message' => 'Invalid email address'], 400)]);

    $deliveryContact = pendingDeliveryContact();

    PushDeliveryContact::dispatchSync($deliveryContact);

    expect($deliveryContact->fresh()->status)->toBe(ContactStatus::Failed)
        ->and($deliveryContact->fresh()->sync_error)->toBe('Invalid email address');

    $delivery = $deliveryContact->delivery->fresh();
    expect($delivery->failed_count)->toBe(1)
        ->and($delivery->status)->toBe(Delivery::STATUS_COMPLETED);
});
