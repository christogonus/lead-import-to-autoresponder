<?php

use App\Enums\TeamPermission;
use App\Enums\TeamRole;
use App\Models\ContactList;
use App\Models\Team;
use App\Models\User;
use Livewire\Livewire;

/**
 * Put a user on a team in a given role and make it their current team.
 */
function memberOfTeam(Team $team, TeamRole $role): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role->value]);
    $user->forceFill(['current_team_id' => $team->id])->save();

    // Reloaded because the factory leaves the personal team it created cached on
    // the currentTeam relation, and the switch above only changes the column —
    // anything reading $user->currentTeam would still get the personal team.
    return $user->fresh();
}

test('only owners and admins carry the delete-list permission', function () {
    expect(TeamRole::Owner->hasPermission(TeamPermission::DeleteList))->toBeTrue()
        ->and(TeamRole::Admin->hasPermission(TeamPermission::DeleteList))->toBeTrue()
        ->and(TeamRole::Member->hasPermission(TeamPermission::DeleteList))->toBeFalse();
});

test('a plain member cannot delete a drafted list', function () {
    $owner = User::factory()->create();
    $team = $owner->currentTeam;
    $member = memberOfTeam($team, TeamRole::Member);

    $list = ContactList::factory()->drafted()->create([
        'team_id' => $team->id,
        'name' => 'Set Aside',
    ]);

    $this->actingAs($member);

    Livewire::test('pages::lists.show', ['contactList' => $list])
        ->set('deleteName', 'Set Aside')
        ->call('deleteList')
        ->assertForbidden();

    $this->assertDatabaseHas('contact_lists', ['id' => $list->id]);
});

test('an admin can delete a drafted list', function () {
    $owner = User::factory()->create();
    $team = $owner->currentTeam;
    $admin = memberOfTeam($team, TeamRole::Admin);

    $list = ContactList::factory()->drafted()->create([
        'team_id' => $team->id,
        'name' => 'Set Aside',
    ]);

    $this->actingAs($admin);

    Livewire::test('pages::lists.show', ['contactList' => $list])
        ->set('deleteName', 'Set Aside')
        ->call('deleteList')
        ->assertHasNoErrors();

    $this->assertDatabaseMissing('contact_lists', ['id' => $list->id]);
});

test('the delete option is hidden from a member who cannot use it', function () {
    $owner = User::factory()->create();
    $team = $owner->currentTeam;
    $member = memberOfTeam($team, TeamRole::Member);

    $list = ContactList::factory()->drafted()->create(['team_id' => $team->id]);

    $this->actingAs($member);

    Livewire::test('pages::lists.show', ['contactList' => $list])
        ->assertDontSee(__('Delete permanently'));
});

test('a plain member can still draft and restore a list', function () {
    $owner = User::factory()->create();
    $team = $owner->currentTeam;
    $member = memberOfTeam($team, TeamRole::Member);

    $list = ContactList::factory()->create(['team_id' => $team->id]);

    $this->actingAs($member);

    Livewire::test('pages::lists.show', ['contactList' => $list])
        ->call('draftList')
        ->assertHasNoErrors();

    expect($list->fresh()->isDraft())->toBeTrue();

    Livewire::test('pages::lists.show', ['contactList' => $list->fresh()])
        ->call('restoreList');

    expect($list->fresh()->isDraft())->toBeFalse();
});

test('a plain member cannot empty the drafts', function () {
    $owner = User::factory()->create();
    $team = $owner->currentTeam;
    $member = memberOfTeam($team, TeamRole::Member);

    $draft = ContactList::factory()->drafted()->create(['team_id' => $team->id]);

    $this->actingAs($member);

    Livewire::test('pages::lists.index')
        ->call('showDrafts')
        ->assertDontSee(__('Empty drafts'))
        ->call('emptyDrafts')
        ->assertForbidden();

    $this->assertDatabaseHas('contact_lists', ['id' => $draft->id]);
});

test('a plain member cannot delete lists by name', function () {
    $owner = User::factory()->create();
    $team = $owner->currentTeam;
    $member = memberOfTeam($team, TeamRole::Member);

    $list = ContactList::factory()->create(['team_id' => $team->id, 'name' => 'Split 1']);

    $this->actingAs($member);

    Livewire::test('pages::lists.index')
        ->assertDontSee(__('Delete by name'))
        ->set('deletePattern', 'Split *')
        ->call('deleteListsByName')
        ->assertForbidden();

    $this->assertDatabaseHas('contact_lists', ['id' => $list->id]);
});

test('an admin can empty the drafts', function () {
    $owner = User::factory()->create();
    $team = $owner->currentTeam;
    $admin = memberOfTeam($team, TeamRole::Admin);

    $draft = ContactList::factory()->drafted()->create(['team_id' => $team->id]);

    $this->actingAs($admin);

    Livewire::test('pages::lists.index')
        ->call('emptyDrafts')
        ->assertHasNoErrors();

    $this->assertDatabaseMissing('contact_lists', ['id' => $draft->id]);
});
