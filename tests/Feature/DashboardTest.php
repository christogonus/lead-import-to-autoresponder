<?php

use App\Models\Contact;
use App\Models\ContactList;
use App\Models\Delivery;
use App\Models\Integration;
use App\Models\User;

test('guests are redirected to the login page', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $response = $this
        ->actingAs($user)
        ->get(route('dashboard'));

    $response->assertOk();
});

test('the dashboard walks a new team through setup', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertSee('Get set up')
        ->assertSee('Connect your email platform')
        ->assertSee('Nothing sent yet');
});

test('the dashboard shows the team\'s numbers and recent sends', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $list = ContactList::factory()->create(['team_id' => $team->id, 'name' => 'Spring webinar']);
    Contact::factory()->count(3)->create(['team_id' => $team->id, 'contact_list_id' => $list->id]);
    $integration = Integration::factory()->create(['team_id' => $team->id]);
    Delivery::factory()->create([
        'team_id' => $team->id,
        'contact_list_id' => $list->id,
        'integration_id' => $integration->id,
        'remote_name' => 'Replay sequence',
        'status' => Delivery::STATUS_COMPLETED,
        'total_count' => 3,
        'synced_count' => 2,
        'failed_count' => 1,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertDontSee('Get set up')
        ->assertSee('Spring webinar')
        ->assertSee('Replay sequence')
        ->assertSee('1 failed')
        ->assertSee('Contacts delivered');
});
