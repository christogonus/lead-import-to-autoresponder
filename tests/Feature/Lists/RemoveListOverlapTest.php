<?php

use App\Actions\Lists\RemoveListOverlap;
use App\Models\Contact;
use App\Models\ContactList;
use App\Models\Delivery;
use App\Models\DeliveryContact;
use App\Models\User;
use Livewire\Livewire;

/**
 * A list on the user's current team holding one contact per given email.
 *
 * @param  array<int, string>  $emails
 */
function overlapTestList(User $user, string $name, array $emails, array $attributes = []): ContactList
{
    $list = ContactList::factory()->create([
        'team_id' => $user->currentTeam->id,
        'name' => $name,
        ...$attributes,
    ]);

    foreach ($emails as $email) {
        Contact::factory()->create([
            'team_id' => $list->team_id,
            'contact_list_id' => $list->id,
            'email' => $email,
        ]);
    }

    return $list;
}

test('removing the overlap deletes this list\'s contacts that are also on the other list', function () {
    $user = User::factory()->create();
    $listA = overlapTestList($user, 'List A', ['a@example.com', 'b@example.com', 'c@example.com']);
    $listB = overlapTestList($user, 'List B', ['b@example.com', 'c@example.com', 'd@example.com']);

    $this->actingAs($user);

    Livewire::test('pages::lists.show', ['contactList' => $listB])
        ->set('overlapListId', $listA->id)
        ->assertSet('overlapCount', 2)
        ->call('removeOverlap')
        ->assertHasNoErrors()
        ->assertDispatched('modal-close', name: 'remove-overlap');

    expect($listB->contacts()->pluck('email')->all())->toBe(['d@example.com'])
        ->and($listA->contacts()->orderBy('email')->pluck('email')->all())
        ->toBe(['a@example.com', 'b@example.com', 'c@example.com']);
});

test('the overlap dialog offers the team\'s other lists to compare against', function () {
    $user = User::factory()->create();
    $listA = overlapTestList($user, 'List A', ['a@example.com']);
    $listB = overlapTestList($user, 'List B', ['a@example.com']);

    $this->actingAs($user);

    // The menu item has to open the dialog through Flux's own trigger — Flux
    // listens for "modal-show", and a bare "open-modal" event opens nothing.
    Livewire::test('pages::lists.show', ['contactList' => $listB])
        ->assertSeeHtml("\$dispatch('modal-show', { name: 'remove-overlap' })")
        ->assertSeeHtml('value="'.$listA->id.'"')
        ->assertSee('List A');
});

test('removing the overlap can compare against a drafted list', function () {
    $user = User::factory()->create();
    $listA = overlapTestList($user, 'List A', ['a@example.com'], ['drafted_at' => now()]);
    $listB = overlapTestList($user, 'List B', ['a@example.com', 'b@example.com']);

    $this->actingAs($user);

    Livewire::test('pages::lists.show', ['contactList' => $listB])
        ->set('overlapListId', $listA->id)
        ->call('removeOverlap')
        ->assertHasNoErrors();

    expect($listB->contacts()->pluck('email')->all())->toBe(['b@example.com']);
});

test('removing the overlap requires a list to compare against', function () {
    $user = User::factory()->create();
    $listB = overlapTestList($user, 'List B', ['a@example.com']);

    $this->actingAs($user);

    Livewire::test('pages::lists.show', ['contactList' => $listB])
        ->call('removeOverlap')
        ->assertHasErrors('overlapListId');
});

test('removing the overlap cannot compare against another team\'s list or the list itself', function (string $which) {
    $user = User::factory()->create();
    $listB = overlapTestList($user, 'List B', ['a@example.com']);

    $reference = $listB;

    if ($which === 'foreign') {
        $reference = ContactList::factory()->create();
        Contact::factory()->create([
            'team_id' => $reference->team_id,
            'contact_list_id' => $reference->id,
            'email' => 'a@example.com',
        ]);
    }

    $this->actingAs($user);

    Livewire::test('pages::lists.show', ['contactList' => $listB])
        ->set('overlapListId', $reference->id)
        ->assertSet('overlapCount', null)
        ->call('removeOverlap')
        ->assertHasErrors('overlapListId');

    expect($listB->contacts()->count())->toBeGreaterThan(0);
})->with(['foreign', 'self']);

test('removing the overlap is refused on a drafted list', function () {
    $user = User::factory()->create();
    $listA = overlapTestList($user, 'List A', ['a@example.com']);
    $listB = overlapTestList($user, 'List B', ['a@example.com'], ['drafted_at' => now()]);

    $this->actingAs($user);

    Livewire::test('pages::lists.show', ['contactList' => $listB])
        ->set('overlapListId', $listA->id)
        ->call('removeOverlap')
        ->assertForbidden();

    expect($listB->contacts()->count())->toBe(1);
});

test('removing the overlap settles a delivery whose remaining contacts were removed', function () {
    $user = User::factory()->create();
    $listA = overlapTestList($user, 'List A', ['a@example.com']);
    $listB = overlapTestList($user, 'List B', ['a@example.com', 'b@example.com']);

    $delivery = Delivery::factory()->create([
        'team_id' => $listB->team_id,
        'contact_list_id' => $listB->id,
        'status' => Delivery::STATUS_PROCESSING,
        'total_count' => 2,
    ]);
    DeliveryContact::factory()->synced()->create([
        'delivery_id' => $delivery->id,
        'contact_id' => $listB->contacts()->where('email', 'b@example.com')->value('id'),
    ]);
    DeliveryContact::factory()->create([
        'delivery_id' => $delivery->id,
        'contact_id' => $listB->contacts()->where('email', 'a@example.com')->value('id'),
    ]);

    $removed = app(RemoveListOverlap::class)->handle($listB, $listA);

    expect($removed)->toBe(1)
        ->and($delivery->fresh())
        ->status->toBe(Delivery::STATUS_COMPLETED)
        ->total_count->toBe(1)
        ->synced_count->toBe(1);
});

test('the overlap action refuses lists from different teams', function () {
    $user = User::factory()->create();
    $listB = overlapTestList($user, 'List B', ['a@example.com']);
    $foreign = ContactList::factory()->create();

    expect(fn () => app(RemoveListOverlap::class)->handle($listB, $foreign))
        ->toThrow(RuntimeException::class);
});
