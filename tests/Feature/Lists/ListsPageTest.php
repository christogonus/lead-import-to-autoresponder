<?php

use App\Models\User;
use Livewire\Livewire;

test('the lists page renders', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('lists.index'))->assertOk();
});

test('a list can be created with just a name', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test('pages::lists.index')
        ->set('name', 'First Contact')
        ->call('createList')
        ->assertHasNoErrors()
        ->assertRedirect();

    $this->assertDatabaseHas('contact_lists', [
        'team_id' => $user->currentTeam->id,
        'name' => 'First Contact',
    ]);
});

test('creating a list requires a name', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test('pages::lists.index')
        ->set('name', '')
        ->call('createList')
        ->assertHasErrors('name');
});
