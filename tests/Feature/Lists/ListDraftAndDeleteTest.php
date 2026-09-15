<?php

use App\Actions\Lists\DeleteContactList;
use App\Models\Contact;
use App\Models\ContactList;
use App\Models\Delivery;
use App\Models\DeliveryContact;
use App\Models\Import;
use App\Models\Integration;
use App\Models\User;
use Livewire\Livewire;

function draftTestList(User $user, array $attributes = []): ContactList
{
    return ContactList::factory()->create([
        'team_id' => $user->currentTeam->id,
        ...$attributes,
    ]);
}

test('a list can be moved to drafts', function () {
    $user = User::factory()->create();
    $list = draftTestList($user);

    $this->actingAs($user);

    Livewire::test('pages::lists.show', ['contactList' => $list])
        ->call('draftList');

    expect($list->fresh()->isDraft())->toBeTrue();
});

test('drafting a list leaves its contacts alone', function () {
    $user = User::factory()->create();
    $list = draftTestList($user);
    Contact::factory()->count(3)->create([
        'team_id' => $list->team_id,
        'contact_list_id' => $list->id,
    ]);

    $this->actingAs($user);

    Livewire::test('pages::lists.show', ['contactList' => $list])
        ->call('draftList');

    expect($list->contacts()->count())->toBe(3);
});

test('a drafted list is hidden from the active index and listed under drafts', function () {
    $user = User::factory()->create();
    $active = draftTestList($user, ['name' => 'Still Working']);
    $drafted = ContactList::factory()->drafted()->create([
        'team_id' => $user->currentTeam->id,
        'name' => 'Set Aside',
    ]);

    $this->actingAs($user);

    Livewire::test('pages::lists.index')
        ->assertSee($active->name)
        ->assertDontSee($drafted->name)
        ->call('showDrafts')
        ->assertSee($drafted->name)
        ->assertDontSee($active->name);
});

test('a list cannot be drafted while a delivery is still sending', function () {
    $user = User::factory()->create();
    $list = draftTestList($user);
    Delivery::factory()->create([
        'team_id' => $list->team_id,
        'contact_list_id' => $list->id,
        'status' => Delivery::STATUS_PROCESSING,
    ]);

    $this->actingAs($user);

    Livewire::test('pages::lists.show', ['contactList' => $list])
        ->call('draftList');

    expect($list->fresh()->isDraft())->toBeFalse();
});

test('a list cannot be drafted while an import is still running', function () {
    $user = User::factory()->create();
    $list = draftTestList($user);
    Import::factory()->create([
        'team_id' => $list->team_id,
        'contact_list_id' => $list->id,
        'status' => Import::STATUS_PROCESSING,
    ]);

    $this->actingAs($user);

    Livewire::test('pages::lists.show', ['contactList' => $list])
        ->call('draftList');

    expect($list->fresh()->isDraft())->toBeFalse();
});

test('a list can be drafted once its delivery has finished', function () {
    $user = User::factory()->create();
    $list = draftTestList($user);
    Delivery::factory()->create([
        'team_id' => $list->team_id,
        'contact_list_id' => $list->id,
        'status' => Delivery::STATUS_COMPLETED,
    ]);

    $this->actingAs($user);

    Livewire::test('pages::lists.show', ['contactList' => $list])
        ->call('draftList');

    expect($list->fresh()->isDraft())->toBeTrue();
});

test('a drafted list can be restored', function () {
    $user = User::factory()->create();
    $list = ContactList::factory()->drafted()->create(['team_id' => $user->currentTeam->id]);

    $this->actingAs($user);

    Livewire::test('pages::lists.show', ['contactList' => $list])
        ->call('restoreList');

    expect($list->fresh()->isDraft())->toBeFalse();
});

test('a drafted list refuses new contacts, imports, sends and splits', function (string $method) {
    $user = User::factory()->create();
    $list = ContactList::factory()->drafted()->create(['team_id' => $user->currentTeam->id]);
    Contact::factory()->count(2)->create([
        'team_id' => $list->team_id,
        'contact_list_id' => $list->id,
    ]);

    $this->actingAs($user);

    Livewire::test('pages::lists.show', ['contactList' => $list])
        ->call($method)
        ->assertForbidden();
})->with(['addContact', 'parseSource', 'runImport', 'sendToDestination', 'splitList']);

test('a drafted list cannot be deleted by another team', function () {
    $user = User::factory()->create();
    $foreignList = ContactList::factory()->drafted()->create();

    $this->actingAs($user)->get(route('lists.show', $foreignList))->assertForbidden();
});

test('an active list cannot be deleted', function () {
    $user = User::factory()->create();
    $list = draftTestList($user, ['name' => 'Still Working']);

    $this->actingAs($user);

    Livewire::test('pages::lists.show', ['contactList' => $list])
        ->set('deleteName', 'Still Working')
        ->call('deleteList');

    $this->assertDatabaseHas('contact_lists', ['id' => $list->id]);
});

test('the delete action refuses a list that was never drafted', function () {
    $list = ContactList::factory()->create();

    expect(fn () => app(DeleteContactList::class)->handle($list))
        ->toThrow(RuntimeException::class);
});

test('deleting a draft requires the list name typed back', function () {
    $user = User::factory()->create();
    $list = ContactList::factory()->drafted()->create([
        'team_id' => $user->currentTeam->id,
        'name' => 'Set Aside',
    ]);

    $this->actingAs($user);

    Livewire::test('pages::lists.show', ['contactList' => $list])
        ->set('deleteName', 'Wrong Name')
        ->call('deleteList')
        ->assertHasErrors('deleteName');

    $this->assertDatabaseHas('contact_lists', ['id' => $list->id]);
});

test('a drafted list is permanently deleted with its contacts and imports', function () {
    $user = User::factory()->create();
    $list = ContactList::factory()->drafted()->create([
        'team_id' => $user->currentTeam->id,
        'name' => 'Set Aside',
    ]);
    $contact = Contact::factory()->create([
        'team_id' => $list->team_id,
        'contact_list_id' => $list->id,
    ]);
    $import = Import::factory()->create([
        'team_id' => $list->team_id,
        'contact_list_id' => $list->id,
    ]);

    $this->actingAs($user);

    Livewire::test('pages::lists.show', ['contactList' => $list])
        ->set('deleteName', 'Set Aside')
        ->call('deleteList')
        ->assertHasNoErrors()
        ->assertRedirect(route('lists.index'));

    $this->assertDatabaseMissing('contact_lists', ['id' => $list->id]);
    $this->assertDatabaseMissing('contacts', ['id' => $contact->id]);
    $this->assertDatabaseMissing('imports', ['id' => $import->id]);
});

test('deleting a drafted list keeps its deliveries and snapshots the list name', function () {
    $user = User::factory()->create();
    $list = ContactList::factory()->drafted()->create([
        'team_id' => $user->currentTeam->id,
        'name' => 'Set Aside',
    ]);
    $contact = Contact::factory()->create([
        'team_id' => $list->team_id,
        'contact_list_id' => $list->id,
    ]);
    $delivery = Delivery::factory()->create([
        'team_id' => $list->team_id,
        'contact_list_id' => $list->id,
        'integration_id' => Integration::factory()->create(['team_id' => $list->team_id])->id,
        'status' => Delivery::STATUS_COMPLETED,
        'synced_count' => 1,
        'total_count' => 1,
    ]);
    DeliveryContact::factory()->create([
        'delivery_id' => $delivery->id,
        'contact_id' => $contact->id,
    ]);

    app(DeleteContactList::class)->handle($list);

    $this->assertDatabaseHas('deliveries', [
        'id' => $delivery->id,
        'contact_list_id' => null,
        'contact_list_name' => 'Set Aside',
        'synced_count' => 1,
    ]);
});

test('emptying the drafts deletes every drafted list and its contacts', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $drafts = ContactList::factory()->drafted()->count(3)->create(['team_id' => $team->id]);
    $active = draftTestList($user, ['name' => 'Still Working']);

    foreach ($drafts as $draft) {
        Contact::factory()->count(2)->create([
            'team_id' => $team->id,
            'contact_list_id' => $draft->id,
        ]);
    }

    $keptContact = Contact::factory()->create([
        'team_id' => $team->id,
        'contact_list_id' => $active->id,
    ]);

    $this->actingAs($user);

    Livewire::test('pages::lists.index')
        ->call('showDrafts')
        ->call('emptyDrafts')
        ->assertHasNoErrors();

    expect($team->contactLists()->drafted()->count())->toBe(0)
        ->and(Contact::count())->toBe(1)
        ->and(Contact::whereKey($keptContact->id)->exists())->toBeTrue();

    $this->assertDatabaseHas('contact_lists', ['id' => $active->id]);
});

test('emptying the drafts leaves another team alone', function () {
    $user = User::factory()->create();
    $foreignDraft = ContactList::factory()->drafted()->create();

    ContactList::factory()->drafted()->create(['team_id' => $user->currentTeam->id]);

    $this->actingAs($user);

    Livewire::test('pages::lists.index')
        ->call('emptyDrafts')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('contact_lists', ['id' => $foreignDraft->id]);
});

test('emptying the drafts keeps the deliveries they were sent from', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $draft = ContactList::factory()->drafted()->create(['team_id' => $team->id, 'name' => 'Set Aside']);

    $delivery = Delivery::factory()->create([
        'team_id' => $team->id,
        'contact_list_id' => $draft->id,
        'integration_id' => Integration::factory()->create(['team_id' => $team->id])->id,
        'status' => Delivery::STATUS_COMPLETED,
    ]);

    $this->actingAs($user);

    Livewire::test('pages::lists.index')->call('emptyDrafts');

    $this->assertDatabaseHas('deliveries', [
        'id' => $delivery->id,
        'contact_list_id' => null,
        'contact_list_name' => 'Set Aside',
    ]);
});

test('emptying an already empty drafts shelf changes nothing', function () {
    $user = User::factory()->create();
    $active = draftTestList($user);

    $this->actingAs($user);

    Livewire::test('pages::lists.index')
        ->call('emptyDrafts')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('contact_lists', ['id' => $active->id]);
});

test('deleting by name removes every matching list on either shelf with its contacts', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $activeMatch = draftTestList($user, ['name' => 'ElechyComplete 15 1']);
    $draftedMatch = ContactList::factory()->drafted()->create(['team_id' => $team->id, 'name' => 'ElechyComplete 15 2']);
    $kept = collect([
        draftTestList($user, ['name' => 'ElechyComplete']),
        draftTestList($user, ['name' => 'ElechyComplete 150 1']),
        draftTestList($user, ['name' => 'Other 15 1']),
    ]);

    Contact::factory()->count(2)->create(['team_id' => $team->id, 'contact_list_id' => $activeMatch->id]);
    Contact::factory()->create(['team_id' => $team->id, 'contact_list_id' => $draftedMatch->id]);
    $keptContact = Contact::factory()->create(['team_id' => $team->id, 'contact_list_id' => $kept->first()->id]);

    $this->actingAs($user);

    Livewire::test('pages::lists.index')
        ->set('deletePattern', 'ElechyComplete 15 *')
        ->assertSet('patternMatches.lists', 2)
        ->assertSet('patternMatches.contacts', 3)
        ->call('deleteListsByName')
        ->assertHasNoErrors()
        ->assertDispatched('modal-close', name: 'delete-by-name');

    $this->assertDatabaseMissing('contact_lists', ['id' => $activeMatch->id]);
    $this->assertDatabaseMissing('contact_lists', ['id' => $draftedMatch->id]);

    foreach ($kept as $list) {
        $this->assertDatabaseHas('contact_lists', ['id' => $list->id, 'drafted_at' => null]);
    }

    expect(Contact::pluck('id')->all())->toBe([$keptContact->id]);
});

test('deleting by name takes percent and underscore literally', function () {
    $user = User::factory()->create();
    $literal = draftTestList($user, ['name' => '50% off_now']);
    $lookalike = draftTestList($user, ['name' => '50X offXnow']);

    $this->actingAs($user);

    Livewire::test('pages::lists.index')
        ->set('deletePattern', '50% off_now')
        ->call('deleteListsByName')
        ->assertHasNoErrors();

    $this->assertDatabaseMissing('contact_lists', ['id' => $literal->id]);
    $this->assertDatabaseHas('contact_lists', ['id' => $lookalike->id]);
});

test('deleting by name refuses a pattern of only wildcards', function (string $pattern) {
    $user = User::factory()->create();
    $list = draftTestList($user);

    $this->actingAs($user);

    Livewire::test('pages::lists.index')
        ->set('deletePattern', $pattern)
        ->assertSet('patternMatches', null)
        ->call('deleteListsByName')
        ->assertHasErrors('deletePattern');

    $this->assertDatabaseHas('contact_lists', ['id' => $list->id]);
})->with(['*', ' * * ', '']);

test('deleting by name skips a matching list with a send still running', function () {
    $user = User::factory()->create();
    $busy = draftTestList($user, ['name' => 'Split 1']);
    $idle = draftTestList($user, ['name' => 'Split 2']);
    Delivery::factory()->create([
        'team_id' => $busy->team_id,
        'contact_list_id' => $busy->id,
        'status' => Delivery::STATUS_PROCESSING,
    ]);

    $this->actingAs($user);

    Livewire::test('pages::lists.index')
        ->set('deletePattern', 'Split *')
        ->assertSet('patternMatches.busy', 1)
        ->call('deleteListsByName');

    $this->assertDatabaseHas('contact_lists', ['id' => $busy->id, 'drafted_at' => null]);
    $this->assertDatabaseMissing('contact_lists', ['id' => $idle->id]);
});

test('deleting by name leaves another team alone', function () {
    $user = User::factory()->create();
    $foreign = ContactList::factory()->create(['name' => 'Split 1']);
    draftTestList($user, ['name' => 'Split 2']);

    $this->actingAs($user);

    Livewire::test('pages::lists.index')
        ->set('deletePattern', 'Split *')
        ->assertSet('patternMatches.lists', 1)
        ->call('deleteListsByName');

    $this->assertDatabaseHas('contact_lists', ['id' => $foreign->id, 'drafted_at' => null]);
});

test('the empty drafts button only appears on the drafts shelf', function () {
    $user = User::factory()->create();
    ContactList::factory()->drafted()->create(['team_id' => $user->currentTeam->id]);

    $this->actingAs($user);

    Livewire::test('pages::lists.index')
        ->assertDontSee(__('Empty drafts'))
        ->call('showDrafts')
        ->assertSee(__('Empty drafts'));
});
