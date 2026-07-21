<?php

use App\Actions\Contacts\ImportContacts;
use App\Models\Contact;
use App\Models\ContactList;

test('valid rows are imported and stored without being sent anywhere', function () {
    $list = ContactList::factory()->create();

    $import = app(ImportContacts::class)->handle($list, [
        ['first_name' => 'Jane', 'last_name' => 'Doe', 'email' => 'Jane@Example.com', 'phone' => '555', 'country' => 'US'],
        ['first_name' => 'John', 'last_name' => 'Roe', 'email' => 'john@example.com'],
    ], 'csv', 'leads.csv');

    expect($import->imported_count)->toBe(2)
        ->and($import->skipped_count)->toBe(0)
        ->and($import->failed_count)->toBe(0);

    // Email is normalized to lowercase before storing.
    expect(Contact::where('email', 'jane@example.com')->exists())->toBeTrue();

    // Importing does not create any deliveries — sending is a separate action.
    expect($list->deliveries()->count())->toBe(0);
});

test('contacts imported without a name fall back to the default names', function () {
    $list = ContactList::factory()->create();

    app(ImportContacts::class)->handle($list, [
        ['email' => 'noname@example.com'],
        ['first_name' => 'Jane', 'email' => 'jane@example.com'],
    ], 'csv');

    $noName = $list->contacts()->where('email', 'noname@example.com')->first();
    $jane = $list->contacts()->where('email', 'jane@example.com')->first();

    expect($noName->first_name)->toBe(Contact::DEFAULT_FIRST_NAME)
        ->and($noName->last_name)->toBeNull()
        ->and($jane->first_name)->toBe('Jane')
        ->and($jane->last_name)->toBeNull();
});

test('invalid emails are counted as failed and not stored', function () {
    $list = ContactList::factory()->create();

    $import = app(ImportContacts::class)->handle($list, [
        ['email' => 'not-an-email'],
        ['email' => ''],
        ['email' => 'good@example.com'],
    ], 'paste');

    expect($import->imported_count)->toBe(1)
        ->and($import->failed_count)->toBe(2)
        ->and($list->contacts()->count())->toBe(1);
});

test('duplicate emails within the batch and against existing contacts are skipped', function () {
    $list = ContactList::factory()->create();
    Contact::factory()->create([
        'team_id' => $list->team_id,
        'contact_list_id' => $list->id,
        'email' => 'existing@example.com',
    ]);

    $import = app(ImportContacts::class)->handle($list, [
        ['email' => 'existing@example.com'],
        ['email' => 'new@example.com'],
        ['email' => 'NEW@example.com'],
    ], 'manual');

    expect($import->imported_count)->toBe(1)
        ->and($import->skipped_count)->toBe(2);
});

test('an email may appear on two different lists', function () {
    $list = ContactList::factory()->create();
    $other = ContactList::factory()->create(['team_id' => $list->team_id]);

    app(ImportContacts::class)->handle($list, [['email' => 'jane@example.com']], 'csv');
    $import = app(ImportContacts::class)->handle($other, [['email' => 'jane@example.com']], 'csv');

    // Uniqueness is scoped per list, not global: the same person can be on the
    // newsletter list and the webinar list.
    expect($import->imported_count)->toBe(1)
        ->and($list->contacts()->count())->toBe(1)
        ->and($other->contacts()->count())->toBe(1);
});

test('re-importing the same file adds nothing and reports every row skipped', function () {
    $list = ContactList::factory()->create();

    $rows = [
        ['email' => 'jane@example.com'],
        ['email' => 'john@example.com'],
    ];

    app(ImportContacts::class)->handle($list, $rows, 'csv', 'leads.csv');
    $second = app(ImportContacts::class)->handle($list, $rows, 'csv', 'leads.csv');

    expect($second->imported_count)->toBe(0)
        ->and($second->skipped_count)->toBe(2)
        ->and($list->contacts()->count())->toBe(2);
});

test('a duplicate that slips past the pre-read snapshot is ignored, not fatal', function () {
    $list = ContactList::factory()->create();

    // Stand in for a concurrent import: the row lands after handle() has already
    // read the list's existing emails, so only the unique index can catch it.
    Contact::insertOrIgnore([[
        'team_id' => $list->team_id,
        'contact_list_id' => $list->id,
        'email' => 'race@example.com',
        'first_name' => Contact::DEFAULT_FIRST_NAME,
        'created_at' => now(),
        'updated_at' => now(),
    ]]);

    $import = app(ImportContacts::class)->handle($list, [
        ['email' => 'race@example.com'],
        ['email' => 'fresh@example.com'],
    ], 'csv');

    expect($import->imported_count)->toBe(1)
        ->and($import->skipped_count)->toBe(1)
        ->and($list->contacts()->where('email', 'race@example.com')->count())->toBe(1);
});
