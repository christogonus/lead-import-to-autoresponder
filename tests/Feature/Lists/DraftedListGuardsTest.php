<?php

use App\Actions\Contacts\ImportContacts;
use App\Actions\Deliveries\SendListToDestination;
use App\Actions\Lists\SplitContactList;
use App\Models\Contact;
use App\Models\ContactList;
use App\Models\Import;
use App\Models\Integration;
use App\Models\User;

test('importing into a drafted list is refused by the action itself', function () {
    $list = ContactList::factory()->drafted()->create();

    expect(fn () => app(ImportContacts::class)->handle($list, [['email' => 'a@example.com']], 'manual'))
        ->toThrow(RuntimeException::class);

    $this->assertDatabaseCount('imports', 0);
});

test('sending a drafted list is refused by the action itself', function () {
    $list = ContactList::factory()->drafted()->create();
    Contact::factory()->create(['team_id' => $list->team_id, 'contact_list_id' => $list->id]);
    $integration = Integration::factory()->create(['team_id' => $list->team_id]);

    expect(fn () => app(SendListToDestination::class)->handle($list, $integration, 'remote-1'))
        ->toThrow(RuntimeException::class);

    $this->assertDatabaseCount('deliveries', 0);
});

test('splitting a drafted list is refused by the action itself', function () {
    $list = ContactList::factory()->drafted()->create();
    Contact::factory()->count(4)->create(['team_id' => $list->team_id, 'contact_list_id' => $list->id]);

    expect(fn () => app(SplitContactList::class)->handle($list, 2))
        ->toThrow(RuntimeException::class);

    expect(ContactList::query()->count())->toBe(1);
});

test('an import that throws is marked failed rather than left processing', function () {
    $list = ContactList::factory()->create();

    // A row whose email is an array blows up inside the row loop, standing in
    // for any mid-import failure. Caught by hand rather than with toThrow so the
    // assertion does not depend on which throwable PHP raises for it.
    try {
        app(ImportContacts::class)->handle($list, [['email' => ['not', 'a', 'string']]], 'csv');

        $this->fail('The import should have thrown.');
    } catch (Throwable) {
        // Expected — what matters is the state it left behind.
    }

    $this->assertDatabaseHas('imports', [
        'contact_list_id' => $list->id,
        'status' => Import::STATUS_FAILED,
    ]);
});

test('an import stranded in processing stops blocking the list once it goes stale', function () {
    $user = User::factory()->create();
    $list = ContactList::factory()->create(['team_id' => $user->currentTeam->id]);

    $stranded = Import::factory()->create([
        'team_id' => $list->team_id,
        'contact_list_id' => $list->id,
        'status' => Import::STATUS_PROCESSING,
    ]);

    expect($list->hasWorkInProgress())->toBeTrue();

    $stranded->forceFill([
        'created_at' => now()->subMinutes(Import::STALE_AFTER_MINUTES + 1),
    ])->save();

    expect($list->fresh()->hasWorkInProgress())->toBeFalse()
        ->and($list->fresh()->isDraftable())->toBeTrue();
});
