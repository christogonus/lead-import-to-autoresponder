<?php

use App\Actions\Contacts\ImportContacts;
use App\Actions\Deliveries\SendListToDestination;
use App\Actions\Suppressions\SuppressEmails;
use App\Enums\ContactStatus;
use App\Enums\SuppressionReason;
use App\Integrations\IntegrationManager;
use App\Jobs\PushDeliveryContact;
use App\Models\Contact;
use App\Models\ContactList;
use App\Models\Delivery;
use App\Models\Integration;
use App\Models\Suppression;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

function blockEmails(Team $team, array $emails, ?SuppressionReason $reason = null, ?User $user = null)
{
    return app(SuppressEmails::class)->handle($team, $emails, $reason ?? SuppressionReason::Unsubscribed, $user);
}

test('blocking an address removes it from every list on the team', function () {
    $team = Team::factory()->create();
    $first = ContactList::factory()->create(['team_id' => $team->id]);
    $second = ContactList::factory()->create(['team_id' => $team->id]);
    $draft = ContactList::factory()->drafted()->create(['team_id' => $team->id]);

    foreach ([$first, $second, $draft] as $list) {
        Contact::factory()->create([
            'team_id' => $team->id,
            'contact_list_id' => $list->id,
            'email' => 'bounced@example.com',
        ]);
    }

    $kept = Contact::factory()->create([
        'team_id' => $team->id,
        'contact_list_id' => $first->id,
        'email' => 'keeper@example.com',
    ]);

    $result = blockEmails($team, ['bounced@example.com']);

    expect($result->blocked)->toBe(1)
        ->and($result->contactsRemoved)->toBe(3)
        ->and($result->listsAffected)->toBe(3)
        ->and(Contact::where('email', 'bounced@example.com')->count())->toBe(0)
        ->and(Contact::whereKey($kept->id)->exists())->toBeTrue();

    $suppression = $team->suppressions()->sole();

    expect($suppression->email)->toBe('bounced@example.com')
        ->and($suppression->reason)->toBe(SuppressionReason::Unsubscribed)
        ->and($suppression->removed_count)->toBe(3);
});

test('another team keeps its own copy of a blocked address', function () {
    $team = Team::factory()->create();
    $other = Team::factory()->create();
    $otherList = ContactList::factory()->create(['team_id' => $other->id]);

    Contact::factory()->create([
        'team_id' => $other->id,
        'contact_list_id' => $otherList->id,
        'email' => 'shared@example.com',
    ]);

    blockEmails($team, ['shared@example.com']);

    expect(Contact::where('email', 'shared@example.com')->count())->toBe(1)
        ->and($other->suppressions()->count())->toBe(0);
});

test('addresses are normalized and de-duplicated before they are blocked', function () {
    $team = Team::factory()->create();
    $list = ContactList::factory()->create(['team_id' => $team->id]);

    Contact::factory()->create([
        'team_id' => $team->id,
        'contact_list_id' => $list->id,
        'email' => 'mixed@example.com',
    ]);

    $result = blockEmails($team, ['  Mixed@Example.com ', 'MIXED@example.com']);

    expect($result->blocked)->toBe(1)
        ->and($result->contactsRemoved)->toBe(1)
        ->and($team->suppressions()->sole()->email)->toBe('mixed@example.com');
});

test('input that is not an email address is reported rather than blocked', function () {
    $team = Team::factory()->create();

    $result = blockEmails($team, ['good@example.com', 'not-an-email']);

    expect($result->blocked)->toBe(1)
        ->and($result->invalid)->toBe(['not-an-email'])
        ->and($team->suppressions()->count())->toBe(1);
});

test('blocking an address twice is safe and sweeps up contacts added since', function () {
    $team = Team::factory()->create();
    $list = ContactList::factory()->create(['team_id' => $team->id]);

    blockEmails($team, ['repeat@example.com']);

    Contact::factory()->create([
        'team_id' => $team->id,
        'contact_list_id' => $list->id,
        'email' => 'repeat@example.com',
    ]);

    $result = blockEmails($team, ['repeat@example.com']);

    expect($result->blocked)->toBe(0)
        ->and($result->alreadyBlocked)->toBe(1)
        ->and($result->contactsRemoved)->toBe(1)
        ->and($team->suppressions()->count())->toBe(1)
        ->and(Contact::where('email', 'repeat@example.com')->count())->toBe(0);
});

test('blocking settles a delivery whose remaining contacts it removed', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $integration = Integration::factory()->create(['team_id' => $team->id]);
    $list = ContactList::factory()->create(['team_id' => $team->id]);

    Contact::factory()->create([
        'team_id' => $team->id,
        'contact_list_id' => $list->id,
        'email' => 'gone@example.com',
    ]);

    $delivery = app(SendListToDestination::class)->handle($list, $integration, 'list-1');

    expect($delivery->status)->toBe(Delivery::STATUS_PROCESSING);

    blockEmails($team, ['gone@example.com']);

    $delivery->refresh();

    expect($delivery->status)->toBe(Delivery::STATUS_COMPLETED)
        ->and($delivery->total_count)->toBe(0)
        ->and($delivery->deliveryContacts()->count())->toBe(0);
});

test('a queued push for a blocked contact does nothing', function () {
    $team = Team::factory()->create();
    $integration = Integration::factory()->create(['team_id' => $team->id]);
    $list = ContactList::factory()->create(['team_id' => $team->id]);

    Contact::factory()->create([
        'team_id' => $team->id,
        'contact_list_id' => $list->id,
        'email' => 'gone@example.com',
    ]);

    Queue::fake();
    $delivery = app(SendListToDestination::class)->handle($list, $integration, 'list-1');
    $deliveryContact = $delivery->deliveryContacts()->sole();

    blockEmails($team, ['gone@example.com']);

    // The push still runs — cancelling cannot pull a job off the queue — but the
    // row it was given is gone with the contact, so it has nothing to send.
    (new PushDeliveryContact($deliveryContact))->handle(app(IntegrationManager::class));

    expect($delivery->fresh()->synced_count)->toBe(0);
});

test('a blocked address is skipped by a later import', function () {
    $team = Team::factory()->create();
    $list = ContactList::factory()->create(['team_id' => $team->id]);

    blockEmails($team, ['bounced@example.com'], SuppressionReason::Bounced);

    $import = app(ImportContacts::class)->handle($list, [
        ['email' => 'Bounced@example.com', 'first_name' => 'Bo'],
        ['email' => 'fresh@example.com', 'first_name' => 'Fresh'],
    ], 'paste');

    expect($import->imported_count)->toBe(1)
        ->and($import->suppressed_count)->toBe(1)
        ->and($import->skipped_count)->toBe(0)
        ->and($list->contacts()->pluck('email')->all())->toBe(['fresh@example.com']);
});

test('a blocked address is never queued by a send', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $integration = Integration::factory()->create(['team_id' => $team->id]);
    $list = ContactList::factory()->create(['team_id' => $team->id]);

    $kept = Contact::factory()->create([
        'team_id' => $team->id,
        'contact_list_id' => $list->id,
        'email' => 'keeper@example.com',
    ]);

    // Blocked without going through the action, so the contact row survives —
    // this is the race the send path guards against.
    Contact::factory()->create([
        'team_id' => $team->id,
        'contact_list_id' => $list->id,
        'email' => 'blocked@example.com',
    ]);

    Suppression::factory()->create(['team_id' => $team->id, 'email' => 'blocked@example.com']);

    $delivery = app(SendListToDestination::class)->handle($list, $integration, 'list-1');

    expect($delivery->total_count)->toBe(1)
        ->and($delivery->deliveryContacts()->sole()->contact_id)->toBe($kept->id);

    Queue::assertPushed(PushDeliveryContact::class, 1);
});

test('a send with nothing but blocked contacts creates no delivery', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $integration = Integration::factory()->create(['team_id' => $team->id]);
    $list = ContactList::factory()->create(['team_id' => $team->id]);

    Contact::factory()->create([
        'team_id' => $team->id,
        'contact_list_id' => $list->id,
        'email' => 'blocked@example.com',
    ]);

    Suppression::factory()->create(['team_id' => $team->id, 'email' => 'blocked@example.com']);

    expect(app(SendListToDestination::class)->handle($list, $integration, 'list-1'))->toBeNull();

    Queue::assertNothingPushed();
});

test('a delivery contact already synced is left alone by a block', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $integration = Integration::factory()->create(['team_id' => $team->id]);
    $list = ContactList::factory()->create(['team_id' => $team->id]);

    Contact::factory()->count(2)->sequence(
        ['email' => 'synced@example.com'],
        ['email' => 'blocked@example.com'],
    )->create([
        'team_id' => $team->id,
        'contact_list_id' => $list->id,
    ]);

    $delivery = app(SendListToDestination::class)->handle($list, $integration, 'list-1');
    $delivery->deliveryContacts()->get()->each->update(['status' => ContactStatus::Synced]);
    $delivery->recount();

    blockEmails($team, ['blocked@example.com']);

    $delivery->refresh();

    expect($delivery->synced_count)->toBe(1)
        ->and($delivery->total_count)->toBe(1)
        ->and($delivery->status)->toBe(Delivery::STATUS_COMPLETED);
});
