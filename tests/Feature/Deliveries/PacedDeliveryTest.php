<?php

use App\Actions\Deliveries\SendListToDestination;
use App\Enums\ContactStatus;
use App\Jobs\PushDeliveryContact;
use App\Models\Contact;
use App\Models\ContactList;
use App\Models\Integration;
use App\Models\Team;
use Illuminate\Support\Facades\Queue;

function pacedListAndIntegration(int $contacts = 60): array
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

test('a delivery without a rate queues every contact immediately', function () {
    Queue::fake();
    [$list, $integration] = pacedListAndIntegration(5);

    $delivery = app(SendListToDestination::class)->handle($list, $integration, 'list-1');

    expect($delivery->isPaced())->toBeFalse()
        ->and($delivery->deliveryContacts()->whereNull('released_at')->count())->toBe(0);

    Queue::assertPushed(PushDeliveryContact::class, 5);
});

test('a paced delivery records its contacts but queues none of them upfront', function () {
    Queue::fake();
    [$list, $integration] = pacedListAndIntegration(60);

    $delivery = app(SendListToDestination::class)->handle($list, $integration, 'list-1', null, 600);

    expect($delivery->isPaced())->toBeTrue()
        ->and($delivery->contacts_per_hour)->toBe(600)
        ->and($delivery->total_count)->toBe(60)
        ->and($delivery->deliveryContacts()->count())->toBe(60)
        ->and($delivery->deliveryContacts()->whereNull('released_at')->count())->toBe(60);

    Queue::assertNothingPushed();
});

test('the drip releases only what the elapsed time allows', function () {
    Queue::fake();
    [$list, $integration] = pacedListAndIntegration(60);

    // 3600/hour is one per second, which keeps the arithmetic legible.
    $delivery = app(SendListToDestination::class)->handle($list, $integration, 'list-1', null, 3600);

    $this->travel(10)->seconds();
    $this->artisan('deliveries:drip')->assertSuccessful();

    // 10 seconds of allowance, widened by up to 30% jitter.
    $released = $delivery->deliveryContacts()->whereNotNull('released_at')->count();

    expect($released)->toBeGreaterThan(0)->toBeLessThanOrEqual(13);

    Queue::assertPushed(PushDeliveryContact::class, $released);
});

test('the drip catches up after a missed run rather than losing the budget', function () {
    Queue::fake();
    [$list, $integration] = pacedListAndIntegration(60);

    $delivery = app(SendListToDestination::class)->handle($list, $integration, 'list-1', null, 3600);

    // Nothing ran for a full minute; the allowance is cumulative, so the whole
    // 60-second budget is still owed rather than reset to a single tick's worth.
    $this->travel(60)->seconds();
    $this->artisan('deliveries:drip')->assertSuccessful();

    // Jitter can shade the catch-up down, but it is an order of magnitude past
    // the ~1 contact a per-tick quota would have allowed.
    expect($delivery->deliveryContacts()->whereNotNull('released_at')->count())
        ->toBeGreaterThanOrEqual(42);

    // Whatever jitter withheld stays owed and drains on subsequent ticks, with
    // no further time needing to pass.
    foreach (range(1, 10) as $tick) {
        $this->artisan('deliveries:drip')->assertSuccessful();
    }

    expect($delivery->deliveryContacts()->whereNotNull('released_at')->count())->toBe(60);
});

test('the drip never releases more than the rate allows', function () {
    Queue::fake();
    [$list, $integration] = pacedListAndIntegration(60);

    $delivery = app(SendListToDestination::class)->handle($list, $integration, 'list-1', null, 3600);

    $this->travel(5)->seconds();
    $this->artisan('deliveries:drip')->assertSuccessful();

    expect($delivery->deliveryContacts()->whereNotNull('released_at')->count())->toBeLessThan(60);
});

test('a rate below one per minute still releases instead of stalling', function () {
    Queue::fake();
    [$list, $integration] = pacedListAndIntegration(5);

    // 30/hour is one every two minutes: a per-tick quota would floor to zero
    // every minute and never send anything.
    $delivery = app(SendListToDestination::class)->handle($list, $integration, 'list-1', null, 30);

    $this->travel(1)->minutes();
    $this->artisan('deliveries:drip')->assertSuccessful();

    expect($delivery->deliveryContacts()->whereNotNull('released_at')->count())->toBe(0);

    $this->travel(1)->minutes();
    $this->artisan('deliveries:drip')->assertSuccessful();

    expect($delivery->deliveryContacts()->whereNotNull('released_at')->count())->toBeGreaterThan(0);
});

test('the drip ignores deliveries that are not paced', function () {
    Queue::fake();
    [$list, $integration] = pacedListAndIntegration(5);

    app(SendListToDestination::class)->handle($list, $integration, 'list-1');
    Queue::fake();

    $this->travel(10)->minutes();
    $this->artisan('deliveries:drip')->assertSuccessful();

    Queue::assertNothingPushed();
});

test('a paced delivery eventually releases every contact and completes', function () {
    Queue::fake();
    [$list, $integration] = pacedListAndIntegration(20);

    $delivery = app(SendListToDestination::class)->handle($list, $integration, 'list-1', null, 3600);

    foreach (range(1, 5) as $tick) {
        $this->travel(30)->seconds();
        $this->artisan('deliveries:drip')->assertSuccessful();
    }

    expect($delivery->deliveryContacts()->whereNull('released_at')->count())->toBe(0)
        ->and($delivery->deliveryContacts()->where('status', ContactStatus::Pending)->count())->toBe(20);

    Queue::assertPushed(PushDeliveryContact::class, 20);
});
