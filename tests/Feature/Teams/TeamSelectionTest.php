<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;

test('the team picker page renders and lists the users teams', function () {
    $user = User::factory()->create();
    $other = Team::factory()->create(['name' => 'Marketing Crew']);
    $other->members()->attach($user, ['role' => TeamRole::Member->value]);

    $this->actingAs($user)
        ->get(route('teams.select'))
        ->assertOk()
        ->assertSee('Marketing Crew');
});

test('visiting a team the user does not belong to redirects to the picker instead of 403', function () {
    $user = User::factory()->create();
    $foreignTeam = Team::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard', ['current_team' => $foreignTeam->slug]))
        ->assertRedirect(route('teams.select'));
});

test('visiting a non-existent team slug redirects to the picker', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard', ['current_team' => 'does-not-exist']))
        ->assertRedirect(route('teams.select'));
});

test('a member can still reach their own team without being redirected', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard', ['current_team' => $user->currentTeam->slug]))
        ->assertOk();
});

test('guests are sent to login, not the picker', function () {
    $team = Team::factory()->create();

    $this->get(route('dashboard', ['current_team' => $team->slug]))
        ->assertRedirect(route('login'));
});
