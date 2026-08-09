<?php

use App\Models\ContactList;
use App\Models\Delivery;
use App\Models\Integration;
use App\Models\User;
use Livewire\Livewire;

function deliveryForUser(User $user, array $attributes = []): Delivery
{
    $team = $user->currentTeam;

    return Delivery::factory()->create([
        'team_id' => $team->id,
        'contact_list_id' => ContactList::factory()->create(['team_id' => $team->id])->id,
        'integration_id' => Integration::factory()->create(['team_id' => $team->id])->id,
        ...$attributes,
    ]);
}

test('the deliveries page renders', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('deliveries.index'))->assertOk();
});

test('it shows this team deliveries and hides other teams', function () {
    $user = User::factory()->create();
    $ours = deliveryForUser($user, ['remote_name' => 'Our Webinar']);
    Delivery::factory()->create(['remote_name' => 'Somebody Elses Webinar']);

    $this->actingAs($user);

    Livewire::test('pages::deliveries.index')
        ->assertSee($ours->contactList->name)
        ->assertSee('Our Webinar')
        ->assertDontSee('Somebody Elses Webinar');
});

test('a delivery whose list was deleted still shows with the snapshotted name', function () {
    $user = User::factory()->create();
    deliveryForUser($user, [
        'contact_list_id' => null,
        'contact_list_name' => 'Long Gone List',
    ]);

    $this->actingAs($user);

    Livewire::test('pages::deliveries.index')
        ->assertSee('Long Gone List')
        ->assertSee('List deleted');
});

test('deleting a drafted list leaves its delivery visible on the deliveries page', function () {
    $user = User::factory()->create();
    $list = ContactList::factory()->drafted()->create([
        'team_id' => $user->currentTeam->id,
        'name' => 'Set Aside',
    ]);
    deliveryForUser($user, [
        'contact_list_id' => $list->id,
        'status' => Delivery::STATUS_COMPLETED,
    ]);

    $this->actingAs($user);

    Livewire::test('pages::lists.show', ['contactList' => $list])
        ->set('deleteName', 'Set Aside')
        ->call('deleteList')
        ->assertHasNoErrors();

    Livewire::test('pages::deliveries.index')
        ->assertSee('Set Aside')
        ->assertSee('List deleted');
});

test('deliveries can be filtered by status', function () {
    $user = User::factory()->create();
    deliveryForUser($user, ['remote_name' => 'Still Going', 'status' => Delivery::STATUS_PROCESSING]);
    deliveryForUser($user, ['remote_name' => 'All Done', 'status' => Delivery::STATUS_COMPLETED]);

    $this->actingAs($user);

    Livewire::test('pages::deliveries.index')
        ->call('filterByStatus', Delivery::STATUS_PROCESSING)
        ->assertSee('Still Going')
        ->assertDontSee('All Done')
        ->call('filterByStatus', Delivery::STATUS_COMPLETED)
        ->assertSee('All Done')
        ->assertDontSee('Still Going');
});

test('deliveries can be searched by destination name', function () {
    $user = User::factory()->create();
    deliveryForUser($user, ['remote_name' => 'Quarterly Webinar']);
    deliveryForUser($user, ['remote_name' => 'Newsletter Signups']);

    $this->actingAs($user);

    Livewire::test('pages::deliveries.index')
        ->set('search', 'Quarterly')
        ->assertSee('Quarterly Webinar')
        ->assertDontSee('Newsletter Signups');
});

test('deliveries can be searched by the name of a list that still exists', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    deliveryForUser($user, [
        'contact_list_id' => ContactList::factory()->create(['team_id' => $team->id, 'name' => 'Autumn Leads'])->id,
        'remote_name' => 'Destination A',
    ]);
    deliveryForUser($user, [
        'contact_list_id' => ContactList::factory()->create(['team_id' => $team->id, 'name' => 'Winter Leads'])->id,
        'remote_name' => 'Destination B',
    ]);

    $this->actingAs($user);

    Livewire::test('pages::deliveries.index')
        ->set('search', 'Autumn')
        ->assertSee('Autumn Leads')
        ->assertDontSee('Winter Leads');
});

test('deliveries can be searched by the snapshotted name of a deleted list', function () {
    $user = User::factory()->create();
    deliveryForUser($user, [
        'contact_list_id' => null,
        'contact_list_name' => 'Vanished Leads',
        'remote_name' => 'Destination A',
    ]);
    deliveryForUser($user, [
        'contact_list_id' => null,
        'contact_list_name' => 'Other Leads',
        'remote_name' => 'Destination B',
    ]);

    $this->actingAs($user);

    Livewire::test('pages::deliveries.index')
        ->set('search', 'Vanished')
        ->assertSee('Vanished Leads')
        ->assertDontSee('Other Leads');
});
