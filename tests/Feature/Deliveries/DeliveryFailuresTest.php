<?php

use App\Enums\IntegrationProvider;
use App\Models\Contact;
use App\Models\ContactList;
use App\Models\Delivery;
use App\Models\DeliveryContact;
use App\Models\Integration;
use App\Models\User;
use Livewire\Livewire;

/**
 * A delivery on the user's current team to an integration of the given provider.
 *
 * @param  array<string, mixed>  $attributes
 */
function failureDelivery(User $user, IntegrationProvider $provider = IntegrationProvider::GoToWebinar, array $attributes = []): Delivery
{
    $team = $user->currentTeam;

    return Delivery::factory()->create([
        'team_id' => $team->id,
        'contact_list_id' => ContactList::factory()->create(['team_id' => $team->id, 'name' => 'Webinar Leads'])->id,
        'integration_id' => Integration::factory()->create(['team_id' => $team->id, 'provider' => $provider])->id,
        'remote_name' => 'Autumn Webinar',
        ...$attributes,
    ]);
}

/**
 * Record a contact's push to the delivery with the given factory state, and
 * settle the delivery's counts as the job would.
 *
 * @param  array<string, mixed>  $contact
 */
function pushOutcome(Delivery $delivery, string $state, array $contact, ?string $error = null): DeliveryContact
{
    $deliveryContact = DeliveryContact::factory()->{$state}()->create([
        'delivery_id' => $delivery->id,
        'contact_id' => Contact::factory()->create([
            'team_id' => $delivery->team_id,
            'contact_list_id' => $delivery->contact_list_id,
            ...$contact,
        ])->id,
        ...($error !== null ? ['sync_error' => $error] : []),
    ]);

    $delivery->recount();

    return $deliveryContact;
}

/**
 * @return array<int, array<int, string>>
 */
function failureCsvRows(string $csv): array
{
    return array_map(str_getcsv(...), preg_split('/\R/', trim($csv)));
}

test('the failures modal lists each failed email with its reason', function () {
    $user = User::factory()->create();
    $delivery = failureDelivery($user);
    pushOutcome($delivery, 'failed', ['email' => 'bounced@example.com'], 'Invalid email address.');
    pushOutcome($delivery, 'failed', ['email' => 'closed@example.com'], 'Webinar is no longer accepting registrants.');
    pushOutcome($delivery, 'synced', ['email' => 'fine@example.com']);

    $this->actingAs($user);

    Livewire::test('delivery-failures')
        ->call('show', $delivery->id)
        ->assertSee('bounced@example.com')
        ->assertSee('Invalid email address.')
        ->assertSee('closed@example.com')
        ->assertSee('Webinar is no longer accepting registrants.')
        ->assertDontSee('fine@example.com')
        ->assertSee(route('deliveries.failures.export', $delivery));
});

test('the failures modal summarises the most common reasons', function () {
    $user = User::factory()->create();
    $delivery = failureDelivery($user);
    pushOutcome($delivery, 'failed', ['email' => 'a@example.com'], 'Invalid email address.');
    pushOutcome($delivery, 'failed', ['email' => 'b@example.com'], 'Invalid email address.');
    pushOutcome($delivery, 'failed', ['email' => 'c@example.com'], 'Token expired.');

    $this->actingAs($user);

    $reasons = Livewire::test('delivery-failures')
        ->call('show', $delivery->id)
        ->instance()
        ->reasons;

    expect($reasons->all())->toBe(['Invalid email address.' => 2, 'Token expired.' => 1]);
});

test('another team\'s delivery failures cannot be opened', function () {
    $user = User::factory()->create();
    $foreign = Delivery::factory()->create();

    $this->actingAs($user);

    Livewire::test('delivery-failures')
        ->call('show', $foreign->id)
        ->assertForbidden();
});

test('failed contacts download as csv with their reasons', function () {
    $user = User::factory()->create();
    $delivery = failureDelivery($user);
    pushOutcome($delivery, 'failed', [
        'first_name' => 'Grace',
        'last_name' => 'Hopper',
        'email' => 'grace@example.com',
        'phone' => null,
        'country' => 'US',
    ], 'Invalid email address.');
    pushOutcome($delivery, 'synced', ['email' => 'fine@example.com']);

    $response = $this->actingAs($user)->get(route('deliveries.failures.export', $delivery));

    $response->assertOk()
        ->assertDownload('webinar-leads-autumn-webinar-failed.csv')
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

    $rows = failureCsvRows($response->streamedContent());

    expect($rows)->toHaveCount(2)
        ->and($rows[0])->toBe(['First Name', 'Last Name', 'Email', 'Phone', 'Country', 'Error', 'Failed At'])
        ->and(array_slice($rows[1], 0, 6))->toBe(['Grace', 'Hopper', 'grace@example.com', '', 'US', 'Invalid email address.']);
});

test('another team\'s failures cannot be downloaded', function () {
    $user = User::factory()->create();
    $foreign = Delivery::factory()->create();

    $this->actingAs($user)->get(route('deliveries.failures.export', $foreign))->assertForbidden();
});

test('the view failed button shows only for deliveries with failures', function () {
    $user = User::factory()->create();
    $failing = failureDelivery($user);
    pushOutcome($failing, 'failed', ['email' => 'bounced@example.com']);

    $this->actingAs($user);

    Livewire::test('pages::deliveries.index')
        ->assertSeeHtml('data-test="view-failures-button"');

    Livewire::test('pages::lists.show', ['contactList' => $failing->contactList])
        ->assertSeeHtml('data-test="view-failures-button"');

    $failing->deliveryContacts()->delete();
    $failing->recount();

    Livewire::test('pages::deliveries.index')
        ->assertDontSeeHtml('data-test="view-failures-button"');
});

test('deliveries can be narrowed to those with failures', function () {
    $user = User::factory()->create();
    $failing = failureDelivery($user, attributes: ['remote_name' => 'Has Failures']);
    pushOutcome($failing, 'failed', ['email' => 'bounced@example.com']);
    $clean = failureDelivery($user, attributes: ['remote_name' => 'All Good']);
    pushOutcome($clean, 'synced', ['email' => 'fine@example.com']);

    $this->actingAs($user);

    Livewire::test('pages::deliveries.index')
        ->assertSee('All Good')
        ->set('failedOnly', true)
        ->assertSee('Has Failures')
        ->assertDontSee('All Good');
});

test('deliveries can be filtered by provider', function () {
    $user = User::factory()->create();
    failureDelivery($user, IntegrationProvider::GoToWebinar, ['remote_name' => 'Webinar Send']);
    failureDelivery($user, IntegrationProvider::Mailchimp, ['remote_name' => 'Newsletter Send']);

    $this->actingAs($user);

    Livewire::test('pages::deliveries.index')
        ->assertSeeHtml('data-test="provider-filter"')
        ->set('provider', IntegrationProvider::GoToWebinar->value)
        ->assertSee('Webinar Send')
        ->assertDontSee('Newsletter Send');
});
