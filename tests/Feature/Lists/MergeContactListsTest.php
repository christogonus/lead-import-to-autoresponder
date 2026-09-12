<?php

use App\Actions\Lists\MergeContactLists;
use App\Models\Contact;
use App\Models\ContactList;
use App\Models\Team;
use App\Models\User;
use Livewire\Livewire;

/**
 * A list on the given team holding one contact per given email.
 *
 * @param  array<int, string>  $emails
 */
function listOfEmails(Team $team, string $name, array $emails): ContactList
{
    $list = ContactList::factory()->create(['team_id' => $team->id, 'name' => $name]);

    foreach ($emails as $email) {
        Contact::factory()->create([
            'team_id' => $team->id,
            'contact_list_id' => $list->id,
            'email' => $email,
        ]);
    }

    return $list;
}

test('merging copies the chosen lists into one new list of unique contacts', function () {
    $team = Team::factory()->create();

    $one = listOfEmails($team, 'List 1', ['a@example.com', 'b@example.com']);
    $two = listOfEmails($team, 'List 2', ['b@example.com', 'c@example.com']);
    $three = listOfEmails($team, 'List 3', ['zzz@example.com']);
    $four = listOfEmails($team, 'List 4', ['c@example.com', 'd@example.com']);

    $result = app(MergeContactLists::class)->handle(collect([$one, $two, $four]), 'Everything');

    expect($result['merged'])->toBe(4)
        ->and($result['duplicates'])->toBe(2)
        ->and($result['list']->name)->toBe('Everything')
        ->and($result['list']->team_id)->toBe($team->id)
        ->and($result['list']->contacts()->orderBy('email')->pluck('email')->all())
        ->toBe(['a@example.com', 'b@example.com', 'c@example.com', 'd@example.com']);

    // The sources are untouched, and a list left out stays out.
    expect($one->contacts()->count())->toBe(2)
        ->and($two->contacts()->count())->toBe(2)
        ->and($four->contacts()->count())->toBe(2)
        ->and($result['list']->contacts()->where('email', 'zzz@example.com')->exists())->toBeFalse()
        ->and($three->contacts()->count())->toBe(1);
});

test('a merged contact keeps its details, taken from the earliest list it is on', function () {
    $team = Team::factory()->create();

    $one = ContactList::factory()->create(['team_id' => $team->id]);
    $original = Contact::factory()->create([
        'team_id' => $team->id,
        'contact_list_id' => $one->id,
        'first_name' => 'Grace',
        'last_name' => 'Hopper',
        'email' => 'grace@example.com',
        'phone' => '+1555',
        'country' => 'US',
    ]);

    $two = ContactList::factory()->create(['team_id' => $team->id]);
    Contact::factory()->create([
        'team_id' => $team->id,
        'contact_list_id' => $two->id,
        'first_name' => 'G.',
        'last_name' => 'Hopper',
        'email' => 'grace@example.com',
        'phone' => null,
        'country' => null,
    ]);

    // Passed newest-first, as the page lists them, to prove the order the
    // caller supplies does not decide which copy wins.
    $result = app(MergeContactLists::class)->handle(collect([$two, $one]), 'Merged');

    $copy = $result['list']->contacts()->sole();

    expect($copy->id)->not->toBe($original->id)
        ->and($copy->team_id)->toBe($team->id)
        ->and($copy->only(['first_name', 'last_name', 'email', 'phone', 'country']))
        ->toBe($original->only(['first_name', 'last_name', 'email', 'phone', 'country']));
});

test('a list appearing on two merges is copied into each', function () {
    $team = Team::factory()->create();

    $shared = listOfEmails($team, 'Shared', ['a@example.com']);
    $one = listOfEmails($team, 'One', ['b@example.com']);
    $two = listOfEmails($team, 'Two', ['c@example.com']);

    $first = app(MergeContactLists::class)->handle(collect([$shared, $one]), 'First');
    $second = app(MergeContactLists::class)->handle(collect([$shared, $two]), 'Second');

    expect($first['list']->contacts()->count())->toBe(2)
        ->and($second['list']->contacts()->count())->toBe(2)
        ->and($shared->contacts()->count())->toBe(1);
});

test('merging refuses fewer than two lists, mixed teams, and drafts', function () {
    $team = Team::factory()->create();
    $one = ContactList::factory()->create(['team_id' => $team->id]);
    $two = ContactList::factory()->create(['team_id' => $team->id]);
    $draft = ContactList::factory()->drafted()->create(['team_id' => $team->id]);
    $foreign = ContactList::factory()->create();

    $merger = app(MergeContactLists::class);

    expect(fn () => $merger->handle(collect([$one]), 'Solo'))
        ->toThrow(RuntimeException::class, 'At least two lists are needed to merge.')
        ->and(fn () => $merger->handle(collect([$one, $foreign]), 'Mixed'))
        ->toThrow(RuntimeException::class, 'Lists from different teams cannot be merged.')
        ->and(fn () => $merger->handle(collect([$one, $draft]), 'Drafted'))
        ->toThrow(RuntimeException::class, 'A drafted list cannot be merged.');

    expect(ContactList::whereIn('name', ['Solo', 'Mixed', 'Drafted'])->exists())->toBeFalse()
        ->and($two->exists)->toBeTrue();
});

test('lists can be merged from the lists page', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $one = listOfEmails($team, 'List 1', ['a@example.com', 'b@example.com']);
    $two = listOfEmails($team, 'List 2', ['b@example.com']);
    $skipped = listOfEmails($team, 'List 3', ['z@example.com']);

    $this->actingAs($user);

    Livewire::test('pages::lists.index')
        ->set('mergeSelection', [(string) $one->id, (string) $two->id])
        ->assertSee('2 unique contacts')
        ->assertSee('1 address appears on more than one list')
        ->set('mergeName', 'Everything')
        ->call('mergeLists')
        ->assertHasNoErrors()
        ->assertRedirect();

    $merged = ContactList::where('name', 'Everything')->sole();

    expect($merged->contacts()->orderBy('email')->pluck('email')->all())->toBe(['a@example.com', 'b@example.com'])
        ->and($one->contacts()->count())->toBe(2)
        ->and($two->contacts()->count())->toBe(1)
        ->and($skipped->contacts()->count())->toBe(1);
});

test('merging from the page needs a name and at least two lists', function () {
    $user = User::factory()->create();
    $list = ContactList::factory()->create(['team_id' => $user->currentTeam->id]);

    $this->actingAs($user);

    Livewire::test('pages::lists.index')
        ->set('mergeSelection', [(string) $list->id])
        ->set('mergeName', '')
        ->call('mergeLists')
        ->assertHasErrors(['mergeName', 'mergeSelection']);

    expect(ContactList::count())->toBe(1);
});

test('merging from the page ignores lists from another team or the drafts shelf', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $mine = listOfEmails($team, 'Mine', ['a@example.com']);
    $draft = ContactList::factory()->drafted()->create(['team_id' => $team->id]);
    $foreign = listOfEmails(Team::factory()->create(), 'Theirs', ['b@example.com']);

    $this->actingAs($user);

    Livewire::test('pages::lists.index')
        ->set('mergeSelection', [(string) $mine->id, (string) $draft->id, (string) $foreign->id])
        ->set('mergeName', 'Everything')
        ->call('mergeLists')
        ->assertHasErrors('mergeSelection');

    expect(ContactList::where('name', 'Everything')->exists())->toBeFalse();
});
