<?php

use App\Models\Contact;
use App\Models\ContactList;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * A list on the user's current team holding the given contacts.
 *
 * @param  array<int, array<string, ?string>>  $contacts
 */
function exportableList(User $user, array $contacts = [], string $name = 'Webinar Leads'): ContactList
{
    $list = ContactList::factory()->create(['team_id' => $user->currentTeam->id, 'name' => $name]);

    foreach ($contacts as $contact) {
        Contact::factory()->create([
            'team_id' => $list->team_id,
            'contact_list_id' => $list->id,
            ...$contact,
        ]);
    }

    return $list;
}

/**
 * @return array<int, array<int, string>>
 */
function csvRows(string $csv): array
{
    return array_map(str_getcsv(...), preg_split('/\R/', trim($csv)));
}

test('a list downloads as a csv of its contacts', function () {
    $user = User::factory()->create();
    $list = exportableList($user, [
        ['first_name' => 'Grace', 'last_name' => 'Hopper', 'email' => 'grace@example.com', 'phone' => '+1 555 0100', 'country' => 'US'],
        ['first_name' => 'Ada', 'last_name' => null, 'email' => 'ada@example.com', 'phone' => null, 'country' => null],
    ]);

    $response = $this->actingAs($user)->get(route('lists.export', $list));

    $response->assertOk()
        ->assertDownload('webinar-leads.csv')
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

    expect(csvRows($response->streamedContent()))->toBe([
        ['First Name', 'Last Name', 'Email', 'Phone', 'Country'],
        ['Grace', 'Hopper', 'grace@example.com', '+1 555 0100', 'US'],
        ['Ada', '', 'ada@example.com', '', ''],
    ]);
});

test('only the list\'s own contacts are exported', function () {
    $user = User::factory()->create();
    $list = exportableList($user, [['email' => 'mine@example.com']]);
    exportableList($user, [['email' => 'other@example.com']], 'Other');

    $csv = $this->actingAs($user)->get(route('lists.export', $list))->streamedContent();

    expect($csv)->toContain('mine@example.com')->not->toContain('other@example.com');
});

test('the greeting placeholder is not exported as a first name', function () {
    $user = User::factory()->create();
    $list = exportableList($user, [['first_name' => Contact::DEFAULT_FIRST_NAME, 'email' => 'nameless@example.com']]);

    $rows = csvRows($this->actingAs($user)->get(route('lists.export', $list))->streamedContent());

    expect($rows[1][0])->toBe('');
});

test('values a spreadsheet would run as formulas are neutralised', function () {
    $user = User::factory()->create();
    $list = exportableList($user, [[
        'first_name' => '=HYPERLINK("http://evil.test","Click")',
        'last_name' => '@SUM(A1)',
        'email' => 'formula@example.com',
        'phone' => '=1+1',
    ]]);

    $rows = csvRows($this->actingAs($user)->get(route('lists.export', $list))->streamedContent());

    expect($rows[1][0])->toBe('\'=HYPERLINK("http://evil.test","Click")')
        ->and($rows[1][1])->toBe("'@SUM(A1)")
        ->and($rows[1][3])->toBe("'=1+1");
});

test('an exported file maps itself when imported again', function () {
    $user = User::factory()->create();
    $list = exportableList($user, [['email' => 'grace@example.com']]);
    $header = csvRows($this->actingAs($user)->get(route('lists.export', $list))->streamedContent())[0];

    $target = exportableList($user, name: 'Target');
    Storage::fake('local');

    Livewire::test('pages::lists.show', ['contactList' => $target])
        ->set('importMode', 'paste')
        ->set('pasted', implode(',', $header)."\nGrace,Hopper,grace@example.com,,US")
        ->call('parseSource')
        ->assertSet('mapping', [
            'email' => '2',
            'first_name' => '0',
            'last_name' => '1',
            'phone' => '3',
            'country' => '4',
        ]);
});

test('a drafted list can still be exported', function () {
    $user = User::factory()->create();
    $list = exportableList($user, [['email' => 'kept@example.com']]);
    $list->draft();

    $this->actingAs($user)->get(route('lists.export', $list))
        ->assertOk()
        ->assertDownload();
});

test('a list from another team cannot be exported', function () {
    $user = User::factory()->create();
    $foreignList = ContactList::factory()->create();

    $this->actingAs($user)->get(route('lists.export', $foreignList))->assertForbidden();
});

test('the export action is offered only when the list has contacts', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->get(route('lists.show', exportableList($user, [['email' => 'a@example.com']])))
        ->assertSee(route('lists.export', ContactList::latest('id')->first()));

    $this->get(route('lists.show', exportableList($user, name: 'Empty')))
        ->assertDontSee('data-test="export-list"', false);
});
