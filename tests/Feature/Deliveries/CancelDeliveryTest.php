<?php

use App\Actions\Deliveries\SendListToDestination;
use App\Enums\ContactStatus;
use App\Jobs\PushDeliveryContact;
use App\Models\Contact;
use App\Models\ContactList;
use App\Models\Delivery;
use App\Models\DeliveryContact;
use App\Models\Integration;
use App\Models\Team;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Build a delivery and its pending contacts directly, so nothing is dispatched
 * and each push in the test below is a deliberate one.
 */
function deliveryAwaitingPush(int $contacts = 3): Delivery
{
    $team = Team::factory()->create();
    $integration = Integration::factory()->create(['team_id' => $team->id]);
    $list = ContactList::factory()->create(['team_id' => $team->id]);

    $delivery = Delivery::factory()->create([
        'team_id' => $team->id,
        'contact_list_id' => $list->id,
        'integration_id' => $integration->id,
        'remote_id' => 'camp123',
        'status' => Delivery::STATUS_PROCESSING,
        'total_count' => $contacts,
    ]);

    Contact::factory()->count($contacts)->create([
        'team_id' => $team->id,
        'contact_list_id' => $list->id,
    ])->each(fn (Contact $contact) => DeliveryContact::factory()->create([
        'delivery_id' => $delivery->id,
        'contact_id' => $contact->id,
        'status' => ContactStatus::Pending,
    ]));

    return $delivery;
}

/**
 * Build a delivery through the real send action, which also sets up pacing.
 */
function sentDelivery(int $contacts = 5, ?int $contactsPerHour = null): Delivery
{
    $team = Team::factory()->create();
    $integration = Integration::factory()->create(['team_id' => $team->id]);
    $list = ContactList::factory()->create(['team_id' => $team->id]);

    Contact::factory()->count($contacts)->create([
        'team_id' => $team->id,
        'contact_list_id' => $list->id,
    ]);

    return app(SendListToDestination::class)->handle($list, $integration, 'list-1', null, $contactsPerHour);
}

test('cancelling stands down every contact still pending', function () {
    Queue::fake();
    $delivery = sentDelivery(5);

    $delivery->cancel();

    expect($delivery->fresh()->status)->toBe(Delivery::STATUS_CANCELLED)
        ->and($delivery->deliveryContacts()->where('status', ContactStatus::Cancelled)->count())->toBe(5)
        ->and($delivery->deliveryContacts()->where('status', ContactStatus::Pending)->count())->toBe(0);
});

test('cancelling leaves contacts already synced alone', function () {
    Http::fake(['api.getresponse.com/v3/*' => Http::response(null, 202)]);
    $delivery = deliveryAwaitingPush(3);

    // One contact makes it out the door before the cancel lands.
    $first = $delivery->deliveryContacts()->orderBy('id')->first();
    PushDeliveryContact::dispatchSync($first);

    $delivery->refresh()->cancel();

    expect($first->fresh()->status)->toBe(ContactStatus::Synced)
        ->and($first->fresh()->synced_at)->not->toBeNull()
        ->and($delivery->fresh()->synced_count)->toBe(1)
        ->and($delivery->deliveryContacts()->where('status', ContactStatus::Cancelled)->count())->toBe(2);
});

test('a queued job for a cancelled delivery pushes nothing', function () {
    Http::fake(['api.getresponse.com/v3/*' => Http::response(null, 202)]);
    $delivery = deliveryAwaitingPush(2);

    $deliveryContact = $delivery->deliveryContacts()->orderBy('id')->first();

    $delivery->cancel();

    // The job was already on the queue when the cancel landed.
    PushDeliveryContact::dispatchSync($deliveryContact);

    expect($deliveryContact->fresh()->status)->toBe(ContactStatus::Cancelled);

    Http::assertNothingSent();
});

test('a contact reporting back after a cancel does not resurrect the delivery', function () {
    $delivery = deliveryAwaitingPush(2);

    // A job that read the delivery before the cancel holds a stale copy.
    $deliveryContact = $delivery->deliveryContacts()->orderBy('id')->first();
    $stale = $deliveryContact->delivery;

    $delivery->cancel();

    $deliveryContact->markSynced('remote-1');
    $stale->recount();

    expect($delivery->fresh()->status)->toBe(Delivery::STATUS_CANCELLED)
        ->and($delivery->fresh()->synced_count)->toBe(1);
});

test('the drip stops releasing contacts once a paced delivery is cancelled', function () {
    Queue::fake();
    $delivery = sentDelivery(60, 3600);

    $delivery->cancel();

    $this->travel(60)->seconds();
    $this->artisan('deliveries:drip')->assertSuccessful();

    expect($delivery->deliveryContacts()->whereNotNull('released_at')->count())->toBe(0);

    Queue::assertNothingPushed();
});

test('resuming puts the stood-down contacts back on the queue', function () {
    Queue::fake();
    $delivery = sentDelivery(4);
    $delivery->cancel();

    Queue::fake();
    $delivery->fresh()->resume();

    expect($delivery->fresh()->status)->toBe(Delivery::STATUS_PROCESSING)
        ->and($delivery->deliveryContacts()->where('status', ContactStatus::Pending)->count())->toBe(4)
        ->and($delivery->deliveryContacts()->where('status', ContactStatus::Cancelled)->count())->toBe(0);

    Queue::assertPushed(PushDeliveryContact::class, 4);
});

test('a resumed contact is actually pushed rather than bailing on the cancel guard', function () {
    Http::fake(['api.getresponse.com/v3/*' => Http::response(null, 202)]);
    $delivery = deliveryAwaitingPush(1);

    $delivery->cancel();

    // The queue runs synchronously here, so resume() dispatching means the push
    // happens inline — exactly the path the cancel guard would have eaten had
    // resume() re-queued the contact before clearing the cancelled status.
    $delivery->fresh()->resume();

    expect($delivery->deliveryContacts()->first()->fresh()->status)->toBe(ContactStatus::Synced)
        ->and($delivery->fresh()->status)->toBe(Delivery::STATUS_COMPLETED);
});

test('resuming leaves synced and failed contacts as they were', function () {
    Http::fake(['api.getresponse.com/v3/*' => Http::response(null, 202)]);
    $delivery = deliveryAwaitingPush(3);

    $contacts = $delivery->deliveryContacts()->orderBy('id')->get();
    PushDeliveryContact::dispatchSync($contacts[0]);
    $contacts[1]->markFailed('Invalid email address');

    // Faked from here so the resumed contact stays queued and observable.
    Queue::fake();

    $delivery->refresh()->cancel();
    $delivery->fresh()->resume();

    expect($contacts[0]->fresh()->status)->toBe(ContactStatus::Synced)
        ->and($contacts[1]->fresh()->status)->toBe(ContactStatus::Failed)
        ->and($contacts[1]->fresh()->sync_error)->toBe('Invalid email address')
        ->and($contacts[2]->fresh()->status)->toBe(ContactStatus::Pending);
});

test('resuming a paced delivery meters from now instead of dumping the backlog', function () {
    Queue::fake();
    $delivery = sentDelivery(60, 3600);

    $this->travel(10)->seconds();
    $delivery->cancel();

    // A long pause would otherwise accrue an allowance covering the whole list.
    $this->travel(2)->hours();
    $delivery->fresh()->resume();

    expect($delivery->fresh()->pacing_started_at->timestamp)->toBe(now()->timestamp);

    $this->artisan('deliveries:drip')->assertSuccessful();

    expect($delivery->deliveryContacts()->whereNotNull('released_at')->count())->toBeLessThan(60);
});

test('resuming a delivery with nothing left to send completes it', function () {
    Http::fake(['api.getresponse.com/v3/*' => Http::response(null, 202)]);
    $delivery = deliveryAwaitingPush(1);

    // Cancel lands after the only contact already went out.
    PushDeliveryContact::dispatchSync($delivery->deliveryContacts()->first());
    $delivery->refresh()->update(['status' => Delivery::STATUS_CANCELLED]);

    $delivery->fresh()->resume();

    expect($delivery->fresh()->status)->toBe(Delivery::STATUS_COMPLETED);
});

test('a completed delivery is not cancellable', function () {
    Http::fake(['api.getresponse.com/v3/*' => Http::response(null, 202)]);
    $delivery = deliveryAwaitingPush(1);

    PushDeliveryContact::dispatchSync($delivery->deliveryContacts()->first());

    expect($delivery->fresh()->status)->toBe(Delivery::STATUS_COMPLETED)
        ->and($delivery->fresh()->isCancellable())->toBeFalse();
});
