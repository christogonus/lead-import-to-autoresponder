<?php

use App\Enums\SuppressionReason;
use App\Models\Contact;
use App\Models\ContactList;
use App\Models\Suppression;
use App\Models\User;
use Livewire\Livewire;

test('the do-not-contact page renders', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('suppressions.index'))->assertOk();
});

test('pasting addresses blocks them and clears them from the team lists', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $list = ContactList::factory()->create(['team_id' => $team->id]);

    Contact::factory()->count(2)->sequence(
        ['email' => 'one@example.com'],
        ['email' => 'two@example.com'],
    )->create([
        'team_id' => $team->id,
        'contact_list_id' => $list->id,
    ]);

    $this->actingAs($user);

    Livewire::test('pages::suppressions.index')
        ->set('emails', "One@example.com\ntwo@example.com, not-an-email")
        ->set('reason', SuppressionReason::Bounced->value)
        ->call('block')
        ->assertHasNoErrors()
        ->assertSet('emails', '');

    expect($team->suppressions()->pluck('email')->sort()->values()->all())
        ->toBe(['one@example.com', 'two@example.com'])
        ->and($team->suppressions()->first()->reason)->toBe(SuppressionReason::Bounced)
        ->and($team->suppressions()->first()->user_id)->toBe($user->id)
        ->and($list->contacts()->count())->toBe(0);
});

test('blocking requires at least one valid address', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test('pages::suppressions.index')
        ->set('emails', 'not-an-email')
        ->call('block')
        ->assertHasErrors('emails');

    expect($user->currentTeam->suppressions()->count())->toBe(0);
});

test('an address can be unblocked', function () {
    $user = User::factory()->create();
    $suppression = Suppression::factory()->create(['team_id' => $user->currentTeam->id]);

    $this->actingAs($user);

    Livewire::test('pages::suppressions.index')
        ->call('unblock', $suppression)
        ->assertHasNoErrors();

    expect(Suppression::whereKey($suppression->id)->exists())->toBeFalse();
});

test('another team\'s blocked address cannot be unblocked', function () {
    $user = User::factory()->create();
    $foreign = Suppression::factory()->create();

    $this->actingAs($user);

    Livewire::test('pages::suppressions.index')
        ->call('unblock', $foreign)
        ->assertForbidden();

    expect(Suppression::whereKey($foreign->id)->exists())->toBeTrue();
});

test('the list only shows the current team\'s blocked addresses', function () {
    $user = User::factory()->create();
    Suppression::factory()->create(['team_id' => $user->currentTeam->id, 'email' => 'mine@example.com']);
    Suppression::factory()->create(['email' => 'theirs@example.com']);

    $this->actingAs($user);

    Livewire::test('pages::suppressions.index')
        ->assertSee('mine@example.com')
        ->assertDontSee('theirs@example.com');
});

test('blocked addresses can be searched', function () {
    $user = User::factory()->create();
    Suppression::factory()->create(['team_id' => $user->currentTeam->id, 'email' => 'needle@example.com']);
    Suppression::factory()->create(['team_id' => $user->currentTeam->id, 'email' => 'haystack@example.com']);

    $this->actingAs($user);

    Livewire::test('pages::suppressions.index')
        ->set('search', 'needle')
        ->assertSee('needle@example.com')
        ->assertDontSee('haystack@example.com');
});

test('a contact can be blocked from the list page', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $list = ContactList::factory()->create(['team_id' => $team->id]);
    $other = ContactList::factory()->create(['team_id' => $team->id]);

    $contact = Contact::factory()->create([
        'team_id' => $team->id,
        'contact_list_id' => $list->id,
        'email' => 'remove-me@example.com',
    ]);

    Contact::factory()->create([
        'team_id' => $team->id,
        'contact_list_id' => $other->id,
        'email' => 'remove-me@example.com',
    ]);

    $this->actingAs($user);

    Livewire::test('pages::lists.show', ['contactList' => $list])
        ->call('blockContact', $contact)
        ->assertHasNoErrors();

    expect(Contact::where('email', 'remove-me@example.com')->count())->toBe(0)
        ->and($team->suppressions()->sole()->email)->toBe('remove-me@example.com');
});

test('a contact on another team\'s list cannot be blocked through this list', function () {
    $user = User::factory()->create();
    $list = ContactList::factory()->create(['team_id' => $user->currentTeam->id]);
    $foreignContact = Contact::factory()->create();

    $this->actingAs($user);

    Livewire::test('pages::lists.show', ['contactList' => $list])
        ->call('blockContact', $foreignContact)
        ->assertForbidden();

    expect(Contact::whereKey($foreignContact->id)->exists())->toBeTrue();
});
