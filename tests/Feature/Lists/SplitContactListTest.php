<?php

use App\Actions\Lists\SplitContactList;
use App\Models\Contact;
use App\Models\ContactList;
use App\Models\User;
use Livewire\Livewire;

function listWithContacts(int $count, string $name = 'Gio Odinga'): ContactList
{
    $list = ContactList::factory()->create(['name' => $name]);

    Contact::factory()->count($count)->create([
        'team_id' => $list->team_id,
        'contact_list_id' => $list->id,
    ]);

    return $list;
}

test('splitting copies the contacts into numbered lists and preserves the original', function () {
    $list = listWithContacts(16);

    $splits = app(SplitContactList::class)->handle($list, 7);

    expect($splits)->toHaveCount(3)
        ->and($splits->map->name->all())->toBe(['Gio Odinga 7 1', 'Gio Odinga 7 2', 'Gio Odinga 7 3'])
        ->and($splits->map(fn (ContactList $split): int => $split->contacts()->count())->all())->toBe([7, 7, 2])
        ->and($list->contacts()->count())->toBe(16);

    // Together the splits carry exactly the original emails, in list order and
    // without overlap.
    $original = $list->contacts()->orderBy('id')->pluck('email');

    expect($splits[0]->contacts()->orderBy('id')->pluck('email')->all())->toBe($original->take(7)->values()->all())
        ->and($splits->flatMap(fn (ContactList $split) => $split->contacts()->orderBy('id')->pluck('email'))->all())->toBe($original->all());
});

test('split lists keep each contact details on the same team', function () {
    $list = listWithContacts(0, 'VIPs');
    $source = Contact::factory()->create([
        'team_id' => $list->team_id,
        'contact_list_id' => $list->id,
        'first_name' => 'Grace',
        'last_name' => 'Hopper',
        'email' => 'grace@example.com',
        'phone' => '+1555',
        'country' => 'US',
    ]);

    $splits = app(SplitContactList::class)->handle($list, 1);

    $copy = $splits->first()->contacts()->first();

    expect($copy->id)->not->toBe($source->id)
        ->and($copy->team_id)->toBe($list->team_id)
        ->and($copy->only(['first_name', 'last_name', 'email', 'phone', 'country']))
        ->toBe($source->only(['first_name', 'last_name', 'email', 'phone', 'country']));
});

test('an even split produces full lists with no remainder list', function () {
    $list = listWithContacts(10);

    $splits = app(SplitContactList::class)->handle($list, 5);

    expect($splits)->toHaveCount(2)
        ->and($splits->map(fn (ContactList $split): int => $split->contacts()->count())->all())->toBe([5, 5]);
});

test('a list can be split from the page', function () {
    $user = User::factory()->create();
    $list = ContactList::factory()->create(['team_id' => $user->currentTeam->id, 'name' => 'Gio Odinga']);
    Contact::factory()->count(5)->create(['team_id' => $list->team_id, 'contact_list_id' => $list->id]);

    $this->actingAs($user);

    Livewire::test('pages::lists.show', ['contactList' => $list])
        ->set('splitSize', 2)
        ->assertSee('Gio Odinga 2 1')
        ->assertSee('Gio Odinga 2 3')
        ->call('splitList')
        ->assertHasNoErrors()
        ->assertRedirect(route('lists.index'));

    expect($user->currentTeam->contactLists()->where('name', 'like', 'Gio Odinga 2 %')->count())->toBe(3)
        ->and($list->contacts()->count())->toBe(5);
});

test('a split size as large as the whole list is rejected', function () {
    $user = User::factory()->create();
    $list = ContactList::factory()->create(['team_id' => $user->currentTeam->id]);
    Contact::factory()->count(5)->create(['team_id' => $list->team_id, 'contact_list_id' => $list->id]);

    $this->actingAs($user);

    Livewire::test('pages::lists.show', ['contactList' => $list])
        ->set('splitSize', 5)
        ->call('splitList')
        ->assertHasErrors('splitSize');

    expect($user->currentTeam->contactLists()->count())->toBe(1);
});
