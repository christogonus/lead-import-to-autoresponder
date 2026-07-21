<?php

use App\Actions\Deliveries\SendListToDestination;
use App\Enums\ContactStatus;
use App\Jobs\PushDeliveryContact;
use App\Models\Contact;
use App\Models\ContactList;
use App\Models\DeliveryContact;
use App\Models\Integration;
use App\Models\Team;
use Illuminate\Support\Facades\Queue;

function teamListAndIntegration(int $contacts = 3): array
{
    $team = Team::factory()->create();
    $integration = Integration::factory()->create(['team_id' => $team->id]);
    $list = ContactList::factory()->create(['team_id' => $team->id]);

    Contact::factory()->count($contacts)->create([
        'team_id' => $team->id,
        'contact_list_id' => $list->id,
    ]);

    return [$list, $integration];
}

test('sending a list creates a delivery and queues a push per contact', function () {
    Queue::fake();
    [$list, $integration] = teamListAndIntegration(3);

    $delivery = app(SendListToDestination::class)->handle($list, $integration, 'list-1', 'Newsletter');

    expect($delivery)->not->toBeNull()
        ->and($delivery->total_count)->toBe(3)
        ->and($delivery->remote_id)->toBe('list-1')
        ->and($delivery->remote_name)->toBe('Newsletter')
        ->and($delivery->deliveryContacts()->count())->toBe(3);

    Queue::assertPushed(PushDeliveryContact::class, 3);
});

test('the same list can be sent to two different destinations independently', function () {
    Queue::fake();
    [$list, $integration] = teamListAndIntegration(2);

    $first = app(SendListToDestination::class)->handle($list, $integration, 'mailchimp-list', 'MC');
    $second = app(SendListToDestination::class)->handle($list, $integration, 'aweber-list', 'AW');

    expect($first->id)->not->toBe($second->id)
        ->and($list->deliveries()->count())->toBe(2)
        ->and($first->total_count)->toBe(2)
        ->and($second->total_count)->toBe(2);

    Queue::assertPushed(PushDeliveryContact::class, 4);
});

test('re-sending to the same destination skips contacts already synced there', function () {
    Queue::fake();
    [$list, $integration] = teamListAndIntegration(3);
    $contacts = $list->contacts()->get();

    // First send; mark one contact as synced and one as failed to that destination.
    $first = app(SendListToDestination::class)->handle($list, $integration, 'list-1', 'Newsletter');

    $first->deliveryContacts()->where('contact_id', $contacts[0]->id)->first()
        ->update(['status' => ContactStatus::Synced]);
    $first->deliveryContacts()->where('contact_id', $contacts[1]->id)->first()
        ->update(['status' => ContactStatus::Failed]);

    // Add a brand-new contact after the first send.
    $newContact = Contact::factory()->create(['team_id' => $list->team_id, 'contact_list_id' => $list->id]);

    // Re-send: the already-synced contact is skipped; failed + still-pending + new are included.
    $second = app(SendListToDestination::class)->handle($list, $integration, 'list-1', 'Newsletter');

    $sentContactIds = $second->deliveryContacts()->pluck('contact_id');

    expect($second->total_count)->toBe(3)
        ->and($sentContactIds)->not->toContain($contacts[0]->id)
        ->and($sentContactIds)->toContain($contacts[1]->id)
        ->and($sentContactIds)->toContain($contacts[2]->id)
        ->and($sentContactIds)->toContain($newContact->id);
});

test('a contact synced to one destination is still sent to a different destination', function () {
    Queue::fake();
    [$list, $integration] = teamListAndIntegration(1);
    $contact = $list->contacts()->first();

    $first = app(SendListToDestination::class)->handle($list, $integration, 'dest-a');
    $first->deliveryContacts()->first()->update(['status' => ContactStatus::Synced]);

    $second = app(SendListToDestination::class)->handle($list, $integration, 'dest-b');

    expect($second)->not->toBeNull()
        ->and($second->deliveryContacts()->pluck('contact_id'))->toContain($contact->id);
});

test('sending returns null when every contact is already synced to the destination', function () {
    Queue::fake();
    [$list, $integration] = teamListAndIntegration(2);

    $first = app(SendListToDestination::class)->handle($list, $integration, 'list-1');
    DeliveryContact::where('delivery_id', $first->id)->update(['status' => ContactStatus::Synced]);

    $second = app(SendListToDestination::class)->handle($list, $integration, 'list-1');

    expect($second)->toBeNull()
        ->and($list->deliveries()->count())->toBe(1);
});
