<?php

use App\Enums\TeamRole;
use App\Models\ContactList;
use App\Models\Delivery;
use App\Models\DeliveryContact;
use App\Models\Integration;
use App\Models\Team;
use App\Models\User;
use Livewire\Livewire;

/**
 * A delivery on the given team, with one per-contact row.
 *
 * @param  array<string, mixed>  $attributes
 */
function deletableDelivery(Team $team, array $attributes = []): Delivery
{
    $delivery = Delivery::factory()->create([
        'team_id' => $team->id,
        'contact_list_id' => ContactList::factory()->create(['team_id' => $team->id])->id,
        'integration_id' => Integration::factory()->create(['team_id' => $team->id])->id,
        ...$attributes,
    ]);

    DeliveryContact::factory()->synced()->create(['delivery_id' => $delivery->id]);

    return $delivery;
}

/**
 * A user who belongs to a shared team in the given role, currently on it.
 */
function memberWithRole(TeamRole $role): User
{
    $team = User::factory()->create()->currentTeam;
    $user = User::factory()->create();

    $team->members()->attach($user, ['role' => $role->value]);
    $user->forceFill(['current_team_id' => $team->id])->save();

    return $user->fresh();
}

test('finished deliveries are deleted with their per-contact rows', function () {
    $user = User::factory()->create();
    $completed = deletableDelivery($user->currentTeam, ['status' => Delivery::STATUS_COMPLETED]);
    $cancelled = deletableDelivery($user->currentTeam, ['status' => Delivery::STATUS_CANCELLED]);
    $contactList = $completed->contactList;

    $this->actingAs($user);

    Livewire::test('delete-deliveries-modal')
        ->call('confirm', [$completed->id, $cancelled->id])
        ->call('deleteDeliveries')
        ->assertDispatched('deliveries-deleted');

    expect(Delivery::count())->toBe(0)
        ->and(DeliveryContact::count())->toBe(0)
        ->and($contactList->fresh())->not->toBeNull();
});

test('a delivery still sending is not deleted', function () {
    $user = User::factory()->create();
    $sending = deletableDelivery($user->currentTeam, ['status' => Delivery::STATUS_PROCESSING]);
    $finished = deletableDelivery($user->currentTeam, ['status' => Delivery::STATUS_COMPLETED]);

    $this->actingAs($user);

    Livewire::test('delete-deliveries-modal')
        ->call('confirm', [$sending->id, $finished->id])
        ->call('deleteDeliveries');

    expect(Delivery::pluck('id')->all())->toBe([$sending->id]);
});

test('another team\'s deliveries are not deleted', function () {
    $user = User::factory()->create();
    $foreign = deletableDelivery(Team::factory()->create());

    $this->actingAs($user);

    Livewire::test('delete-deliveries-modal')
        ->call('confirm', [$foreign->id])
        ->call('deleteDeliveries');

    expect($foreign->fresh())->not->toBeNull();
});

test('members without the delete-list permission cannot delete deliveries', function () {
    $user = memberWithRole(TeamRole::Member);
    $delivery = deletableDelivery($user->currentTeam);

    $this->actingAs($user);

    Livewire::test('delete-deliveries-modal')
        ->call('confirm', [$delivery->id])
        ->assertForbidden();

    Livewire::test('pages::deliveries.index')
        ->assertDontSeeHtml('data-test="delete-delivery-button"')
        ->assertDontSeeHtml('data-test="select-delivery"');

    expect($delivery->fresh())->not->toBeNull();
});

test('admins can delete deliveries', function () {
    $user = memberWithRole(TeamRole::Admin);
    $delivery = deletableDelivery($user->currentTeam);

    $this->actingAs($user);

    Livewire::test('delete-deliveries-modal')
        ->call('confirm', [$delivery->id])
        ->call('deleteDeliveries');

    expect($delivery->fresh())->toBeNull();
});

test('selected deliveries on the page can be bulk deleted', function () {
    $user = User::factory()->create();
    $first = deletableDelivery($user->currentTeam, ['remote_name' => 'First Send']);
    $second = deletableDelivery($user->currentTeam, ['remote_name' => 'Second Send']);
    $sending = deletableDelivery($user->currentTeam, ['remote_name' => 'Still Sending', 'status' => Delivery::STATUS_PROCESSING]);

    $this->actingAs($user);

    $page = Livewire::test('pages::deliveries.index')
        ->call('togglePageSelection');

    expect($page->get('selected'))->toEqualCanonicalizing([(string) $first->id, (string) $second->id]);

    $page->call('confirmDeleteSelected')
        ->assertDispatched('confirm-delete-deliveries', deliveryIds: $page->get('selected'));

    Livewire::test('delete-deliveries-modal')
        ->call('confirm', $page->get('selected'))
        ->call('deleteDeliveries');

    $page->dispatch('deliveries-deleted')
        ->assertSet('selected', [])
        ->assertDontSee('First Send')
        ->assertDontSee('Second Send')
        ->assertSee('Still Sending');
});

test('selecting the page again clears the selection', function () {
    $user = User::factory()->create();
    deletableDelivery($user->currentTeam);

    $this->actingAs($user);

    Livewire::test('pages::deliveries.index')
        ->call('togglePageSelection')
        ->call('togglePageSelection')
        ->assertSet('selected', []);
});

test('a delivery can be deleted from its list page', function () {
    $user = User::factory()->create();
    $delivery = deletableDelivery($user->currentTeam, ['remote_name' => 'Autumn Webinar']);

    $this->actingAs($user);

    $page = Livewire::test('pages::lists.show', ['contactList' => $delivery->contactList])
        ->assertSee('Autumn Webinar')
        ->assertSeeHtml('data-test="delete-delivery-button"');

    Livewire::test('delete-deliveries-modal')
        ->call('confirm', [$delivery->id])
        ->call('deleteDeliveries');

    $page->dispatch('deliveries-deleted')
        ->assertDontSee('Autumn Webinar');
});

test('the delete button is not offered for a delivery still sending', function () {
    $user = User::factory()->create();
    deletableDelivery($user->currentTeam, ['status' => Delivery::STATUS_PROCESSING]);

    $this->actingAs($user);

    Livewire::test('pages::deliveries.index')
        ->assertDontSeeHtml('data-test="delete-delivery-button"')
        ->assertDontSeeHtml('data-test="select-delivery"');
});
