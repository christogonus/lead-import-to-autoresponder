<?php

use App\Enums\TeamRole;
use App\Models\User;
use Database\Factories\UserFactory;
use Laravel\Paddle\Subscription;

test('a subscribed owner reaches their workspace', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('dashboard'))->assertOk();
});

test('an owner without a subscription is sent to billing', function () {
    $user = User::factory()->unsubscribed()->create();

    $this->actingAs($user)
        ->get(route('lists.index'))
        ->assertRedirect(route('billing.show'));
});

test('a cancelled subscription keeps working until the paid period ends', function () {
    $user = User::factory()->unsubscribed()->create();
    UserFactory::subscribe($user)->update(['ends_at' => now()->addDays(10)]);

    $this->actingAs($user)->get(route('dashboard'))->assertOk();
});

test('an expired subscription is sent to billing', function () {
    $user = User::factory()->unsubscribed()->create();
    UserFactory::subscribe($user, status: Subscription::STATUS_CANCELED)->update(['ends_at' => now()->subDay()]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(route('billing.show'));
});

test('a past due subscription keeps working while Paddle retries the card', function () {
    $user = User::factory()->unsubscribed()->create();
    UserFactory::subscribe($user, status: Subscription::STATUS_PAST_DUE);

    $this->actingAs($user)->get(route('dashboard'))->assertOk();
});

test('invited members ride on the owner\'s subscription', function () {
    $owner = User::factory()->create();
    $member = User::factory()->unsubscribed()->create();
    $team = $owner->currentTeam;
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $this->actingAs($member)
        ->get(route('dashboard', ['current_team' => $team->slug]))
        ->assertOk();
});

test('members of a lapsed team are told to ask the owner', function () {
    $owner = User::factory()->unsubscribed()->create();
    $member = User::factory()->create();
    $team = $owner->currentTeam;
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $this->actingAs($member)
        ->get(route('dashboard', ['current_team' => $team->slug]))
        ->assertRedirect(route('teams.select'))
        ->assertSessionHas('status');
});

test('billing stays reachable without a subscription', function () {
    $user = User::factory()->unsubscribed()->create();

    $this->actingAs($user)
        ->get(route('billing.show'))
        ->assertOk()
        ->assertSee('$24')
        ->assertSee('$17');
});

test('guests cannot reach billing', function () {
    $this->get(route('billing.show'))->assertRedirect(route('login'));
});
