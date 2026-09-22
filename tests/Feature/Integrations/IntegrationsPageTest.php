<?php

use App\Models\Integration;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

test('the integrations page renders', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('integrations.index'))->assertOk();
});

test('a valid integration can be connected', function () {
    Http::fake(['api.getresponse.com/v3/accounts' => Http::response([], 200)]);

    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::integrations.index')
        ->set('name', 'My GetResponse')
        ->set('credentials.api_key', 'valid-key')
        ->call('connect')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('integrations', [
        'team_id' => $user->currentTeam->id,
        'name' => 'My GetResponse',
        'provider' => 'getresponse',
        'status' => 'connected',
    ]);

    // Credentials are stored encrypted but decrypt back to the raw value.
    expect(Integration::first()->credentials)->toBe(['api_key' => 'valid-key']);
});

test('a systeme.io integration can be connected through the same flow', function () {
    Http::fake(['api.systeme.io/api/tags*' => Http::response(['items' => [], 'hasMore' => false], 200)]);

    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::integrations.index')
        ->set('provider', 'systeme_io')
        ->set('name', 'My Systeme.io')
        ->set('credentials.api_key', 'valid-key')
        ->call('connect')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('integrations', [
        'team_id' => $user->currentTeam->id,
        'name' => 'My Systeme.io',
        'provider' => 'systeme_io',
        'status' => 'connected',
    ]);
});

test('a mailchimp integration can be connected through the same flow', function () {
    Http::fake(['us21.api.mailchimp.com/3.0/ping' => Http::response([], 200)]);

    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::integrations.index')
        ->set('provider', 'mailchimp')
        ->set('name', 'My Mailchimp')
        ->set('credentials.api_key', 'valid-key-us21')
        ->call('connect')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('integrations', [
        'team_id' => $user->currentTeam->id,
        'name' => 'My Mailchimp',
        'provider' => 'mailchimp',
        'status' => 'connected',
    ]);
});

test('a birdsend integration can be connected through the same flow', function () {
    Http::fake(['api.birdsend.co/v1/tags*' => Http::response(['data' => [], 'meta' => ['last_page' => 1]], 200)]);

    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::integrations.index')
        ->set('provider', 'birdsend')
        ->set('name', 'My BirdSend')
        ->set('credentials.api_key', 'valid-token')
        ->call('connect')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('integrations', [
        'team_id' => $user->currentTeam->id,
        'name' => 'My BirdSend',
        'provider' => 'birdsend',
        'status' => 'connected',
    ]);
});

test('invalid credentials are rejected and nothing is stored', function () {
    Http::fake(['api.getresponse.com/v3/accounts' => Http::response(['message' => 'Unauthorized'], 401)]);

    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::integrations.index')
        ->set('name', 'Bad')
        ->set('credentials.api_key', 'nope')
        ->call('connect')
        ->assertHasErrors('credentials');

    $this->assertDatabaseCount('integrations', 0);
});

test('an integration can be disconnected', function () {
    $user = User::factory()->create();
    $integration = Integration::factory()->create(['team_id' => $user->currentTeam->id]);

    $this->actingAs($user);

    Livewire::test('pages::integrations.index')
        ->call('disconnect', $integration->id)
        ->assertHasNoErrors();

    $this->assertDatabaseMissing('integrations', ['id' => $integration->id]);
});

test('a user cannot disconnect another teams integration', function () {
    $user = User::factory()->create();
    $other = Integration::factory()->create();

    $this->actingAs($user);

    Livewire::test('pages::integrations.index')
        ->call('disconnect', $other->id)
        ->assertForbidden();
});

test('a sender.net integration can be connected through the same flow', function () {
    Http::fake(['api.sender.net/v2/groups*' => Http::response(['data' => [], 'meta' => ['last_page' => 1]], 200)]);

    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::integrations.index')
        ->set('provider', 'sender_net')
        ->set('name', 'My Sender')
        ->set('credentials.api_key', 'valid-token')
        ->call('connect')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('integrations', [
        'team_id' => $user->currentTeam->id,
        'name' => 'My Sender',
        'provider' => 'sender_net',
        'status' => 'connected',
    ]);
});

test('sender.net credentials that the provider rejects are not saved', function () {
    Http::fake(['api.sender.net/v2/groups*' => Http::response(['message' => 'Unauthenticated.'], 401)]);

    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::integrations.index')
        ->set('provider', 'sender_net')
        ->set('name', 'My Sender')
        ->set('credentials.api_key', 'bad-token')
        ->call('connect')
        ->assertHasErrors('credentials');

    $this->assertDatabaseCount('integrations', 0);
});

test('a sendx integration can be connected through the same flow', function () {
    Http::fake(['api.sendx.io/api/v1/rest/list*' => Http::response([], 200)]);

    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::integrations.index')
        ->set('provider', 'sendx')
        ->set('name', 'My SendX')
        ->set('credentials.api_key', 'valid-key')
        ->call('connect')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('integrations', [
        'team_id' => $user->currentTeam->id,
        'name' => 'My SendX',
        'provider' => 'sendx',
        'status' => 'connected',
    ]);
});

test('sendx credentials that the provider rejects are not saved', function () {
    Http::fake(['api.sendx.io/api/v1/rest/list*' => Http::response(['status' => 401, 'message' => 'The Team ID or API Key specified is not valid'], 401)]);

    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::integrations.index')
        ->set('provider', 'sendx')
        ->set('name', 'My SendX')
        ->set('credentials.api_key', 'bad-key')
        ->call('connect')
        ->assertHasErrors('credentials');

    $this->assertDatabaseCount('integrations', 0);
});

test('a sendpulse integration can be connected through the same flow', function () {
    Http::fake(['api.sendpulse.com/addressbooks*' => Http::response([], 200)]);

    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::integrations.index')
        ->set('provider', 'sendpulse')
        ->set('name', 'My SendPulse')
        ->set('credentials.api_key', 'valid-key')
        ->call('connect')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('integrations', [
        'team_id' => $user->currentTeam->id,
        'name' => 'My SendPulse',
        'provider' => 'sendpulse',
        'status' => 'connected',
    ]);
});

test('sendpulse credentials that the provider rejects are not saved', function () {
    Http::fake(['api.sendpulse.com/addressbooks*' => Http::response(['error' => 'invalid_client', 'message' => 'Client authentication failed.'], 401)]);

    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::integrations.index')
        ->set('provider', 'sendpulse')
        ->set('name', 'My SendPulse')
        ->set('credentials.api_key', 'bad-key')
        ->call('connect')
        ->assertHasErrors('credentials');

    $this->assertDatabaseCount('integrations', 0);
});
