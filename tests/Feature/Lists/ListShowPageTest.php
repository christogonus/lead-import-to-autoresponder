<?php

use App\Actions\Deliveries\SendListToDestination;
use App\Enums\ContactStatus;
use App\Jobs\PushDeliveryContact;
use App\Models\Contact;
use App\Models\ContactList;
use App\Models\Delivery;
use App\Models\Integration;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

function listForUser(User $user): ContactList
{
    return ContactList::factory()->create(['team_id' => $user->currentTeam->id]);
}

test('the list detail page renders', function () {
    $user = User::factory()->create();
    $list = listForUser($user);

    $this->actingAs($user)->get(route('lists.show', $list))->assertOk();
});

test('a list cannot be viewed by a member of another team', function () {
    $user = User::factory()->create();
    $foreignList = ContactList::factory()->create();

    $this->actingAs($user)->get(route('lists.show', $foreignList))->assertForbidden();
});

test('a contact can be added manually', function () {
    $user = User::factory()->create();
    $list = listForUser($user);

    $this->actingAs($user);

    Livewire::test('pages::lists.show', ['contactList' => $list])
        ->set('manual.first_name', 'Jane')
        ->set('manual.email', 'jane@example.com')
        ->call('addContact')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('contacts', [
        'contact_list_id' => $list->id,
        'email' => 'jane@example.com',
    ]);
});

test('adding a duplicate email surfaces an error', function () {
    $user = User::factory()->create();
    $list = listForUser($user);
    Contact::factory()->create([
        'team_id' => $list->team_id,
        'contact_list_id' => $list->id,
        'email' => 'dupe@example.com',
    ]);

    $this->actingAs($user);

    Livewire::test('pages::lists.show', ['contactList' => $list])
        ->set('manual.email', 'dupe@example.com')
        ->call('addContact')
        ->assertHasErrors('manual.email');
});

test('contacts can be imported by pasting rows and mapping columns', function () {
    Storage::fake('local');
    $user = User::factory()->create();
    $list = listForUser($user);

    $this->actingAs($user);

    Livewire::test('pages::lists.show', ['contactList' => $list])
        ->set('importMode', 'paste')
        ->set('pasted', "first_name,last_name,email\nJane,Doe,jane@example.com\nJohn,Roe,john@example.com")
        ->call('parseSource')
        ->assertSet('importStep', 'map')
        ->assertSet('mapping.email', '2')
        ->call('runImport')
        ->assertHasNoErrors()
        ->assertSet('importPath', '');

    expect($list->contacts()->count())->toBe(2);
});

test('a large CSV upload advances to mapping and imports without holding rows in state', function () {
    Storage::fake('local');
    $user = User::factory()->create();
    $list = listForUser($user);

    // Build a CSV with many rows — enough that holding every row in the Livewire
    // snapshot would be a problem.
    $rows = ['eMail,First Name,Last Name'];
    for ($i = 0; $i < 3000; $i++) {
        $rows[] = "user{$i}@example.com,First{$i},Last{$i}";
    }
    $csv = UploadedFile::fake()->createWithContent('leads.csv', implode("\n", $rows));

    $this->actingAs($user);

    $component = Livewire::test('pages::lists.show', ['contactList' => $list])
        ->set('file', $csv)
        ->call('parseSource')
        ->assertHasNoErrors()
        ->assertSet('importStep', 'map')
        ->assertSet('importRowCount', 3000)
        ->assertSet('mapping.email', '0');

    // The file lives on disk, not in the component snapshot.
    expect($component->get('importPath'))->toStartWith('imports/');

    $component->call('runImport')->assertHasNoErrors()->assertSet('importPath', '');

    expect($list->contacts()->count())->toBe(3000);
});

test('a list can be sent to a destination from the page', function () {
    Queue::fake();
    Http::fake(['api.getresponse.com/v3/campaigns*' => Http::response([
        ['campaignId' => 'camp1', 'name' => 'Newsletter'],
    ], 200)]);

    $user = User::factory()->create();
    $list = listForUser($user);
    Contact::factory()->count(2)->create(['team_id' => $list->team_id, 'contact_list_id' => $list->id]);
    $integration = Integration::factory()->create(['team_id' => $user->currentTeam->id]);

    $this->actingAs($user);

    Livewire::test('pages::lists.show', ['contactList' => $list])
        ->set('sendIntegrationId', $integration->id)
        ->set('sendRemoteId', 'camp1')
        ->call('sendToDestination')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('deliveries', [
        'contact_list_id' => $list->id,
        'integration_id' => $integration->id,
        'remote_id' => 'camp1',
        'remote_name' => 'Newsletter',
        'total_count' => 2,
    ]);

    Queue::assertPushed(PushDeliveryContact::class, 2);
});

test('a pending delivery can be cancelled from the page', function () {
    Queue::fake();

    $user = User::factory()->create();
    $list = listForUser($user);
    Contact::factory()->count(3)->create(['team_id' => $list->team_id, 'contact_list_id' => $list->id]);
    $integration = Integration::factory()->create(['team_id' => $user->currentTeam->id]);

    $delivery = app(SendListToDestination::class)->handle($list, $integration, 'camp1');

    $this->actingAs($user);

    Livewire::test('pages::lists.show', ['contactList' => $list])
        ->call('cancelDelivery', $delivery)
        ->assertHasNoErrors();

    expect($delivery->fresh()->status)->toBe(Delivery::STATUS_CANCELLED)
        ->and($delivery->deliveryContacts()->where('status', ContactStatus::Cancelled)->count())->toBe(3);
});

test('a delivery belonging to another list cannot be cancelled', function () {
    Queue::fake();

    $user = User::factory()->create();
    $list = listForUser($user);
    $foreignDelivery = Delivery::factory()->create(['status' => Delivery::STATUS_PROCESSING]);

    $this->actingAs($user);

    Livewire::test('pages::lists.show', ['contactList' => $list])
        ->call('cancelDelivery', $foreignDelivery)
        ->assertForbidden();

    expect($foreignDelivery->fresh()->status)->toBe(Delivery::STATUS_PROCESSING);
});

test('a cancelled delivery can be resumed from the page', function () {
    Queue::fake();

    $user = User::factory()->create();
    $list = listForUser($user);
    Contact::factory()->count(3)->create(['team_id' => $list->team_id, 'contact_list_id' => $list->id]);
    $integration = Integration::factory()->create(['team_id' => $user->currentTeam->id]);

    $delivery = app(SendListToDestination::class)->handle($list, $integration, 'camp1');
    $delivery->cancel();

    $this->actingAs($user);

    Livewire::test('pages::lists.show', ['contactList' => $list])
        ->call('resumeDelivery', $delivery)
        ->assertHasNoErrors();

    expect($delivery->fresh()->status)->toBe(Delivery::STATUS_PROCESSING)
        ->and($delivery->deliveryContacts()->where('status', ContactStatus::Pending)->count())->toBe(3);
});

test('a delivery belonging to another list cannot be resumed', function () {
    Queue::fake();

    $user = User::factory()->create();
    $list = listForUser($user);
    $foreignDelivery = Delivery::factory()->create(['status' => Delivery::STATUS_CANCELLED]);

    $this->actingAs($user);

    Livewire::test('pages::lists.show', ['contactList' => $list])
        ->call('resumeDelivery', $foreignDelivery)
        ->assertForbidden();

    expect($foreignDelivery->fresh()->status)->toBe(Delivery::STATUS_CANCELLED);
});

test('a cancelled delivery cannot be retried back onto the queue', function () {
    Queue::fake();

    $user = User::factory()->create();
    $list = listForUser($user);
    Contact::factory()->count(2)->create(['team_id' => $list->team_id, 'contact_list_id' => $list->id]);
    $integration = Integration::factory()->create(['team_id' => $user->currentTeam->id]);

    $delivery = app(SendListToDestination::class)->handle($list, $integration, 'camp1');
    $delivery->cancel();

    $this->actingAs($user);

    Livewire::test('pages::lists.show', ['contactList' => $list])
        ->call('retryDelivery', $delivery)
        ->assertForbidden();

    expect($delivery->fresh()->status)->toBe(Delivery::STATUS_CANCELLED);
});

test('contacts can be searched by an email fragment', function () {
    $user = User::factory()->create();
    $list = listForUser($user);

    Contact::factory()->create(['team_id' => $list->team_id, 'contact_list_id' => $list->id, 'email' => 'ada@gmail.com']);
    Contact::factory()->create(['team_id' => $list->team_id, 'contact_list_id' => $list->id, 'email' => 'grace@gmail.com']);
    Contact::factory()->create(['team_id' => $list->team_id, 'contact_list_id' => $list->id, 'email' => 'linus@outlook.com']);

    $this->actingAs($user);

    Livewire::test('pages::lists.show', ['contactList' => $list])
        ->set('search', '@gmail')
        ->assertSee('ada@gmail.com')
        ->assertSee('grace@gmail.com')
        ->assertDontSee('linus@outlook.com');
});

test('searching resets to the first page of results', function () {
    $user = User::factory()->create();
    $list = listForUser($user);
    Contact::factory()->count(30)->create(['team_id' => $list->team_id, 'contact_list_id' => $list->id]);

    $this->actingAs($user);

    Livewire::test('pages::lists.show', ['contactList' => $list])
        ->set('paginators.page', 2)
        ->set('search', 'example')
        ->assertSet('paginators.page', 1);
});

test('a single contact email can be edited inline', function () {
    $user = User::factory()->create();
    $list = listForUser($user);
    $contact = Contact::factory()->create([
        'team_id' => $list->team_id,
        'contact_list_id' => $list->id,
        'email' => 'old@example.com',
    ]);

    $this->actingAs($user);

    Livewire::test('pages::lists.show', ['contactList' => $list])
        ->call('editEmail', $contact)
        ->assertSet('editingEmail', 'old@example.com')
        ->set('editingEmail', '  NEW@Example.com ')
        ->call('saveEmail')
        ->assertHasNoErrors()
        ->assertSet('editingContactId', null);

    expect($contact->fresh()->email)->toBe('new@example.com');
});

test('an edited email cannot collide with another contact on the same list', function () {
    $user = User::factory()->create();
    $list = listForUser($user);
    $contact = Contact::factory()->create(['team_id' => $list->team_id, 'contact_list_id' => $list->id, 'email' => 'one@example.com']);
    Contact::factory()->create(['team_id' => $list->team_id, 'contact_list_id' => $list->id, 'email' => 'two@example.com']);

    $this->actingAs($user);

    Livewire::test('pages::lists.show', ['contactList' => $list])
        ->call('editEmail', $contact)
        ->set('editingEmail', 'two@example.com')
        ->call('saveEmail')
        ->assertHasErrors(['editingEmail' => 'unique']);

    expect($contact->fresh()->email)->toBe('one@example.com');
});

test('an email that is unchanged apart from case still saves', function () {
    $user = User::factory()->create();
    $list = listForUser($user);
    $contact = Contact::factory()->create(['team_id' => $list->team_id, 'contact_list_id' => $list->id, 'email' => 'same@example.com']);

    $this->actingAs($user);

    Livewire::test('pages::lists.show', ['contactList' => $list])
        ->call('editEmail', $contact)
        ->set('editingEmail', 'SAME@example.com')
        ->call('saveEmail')
        ->assertHasNoErrors();

    expect($contact->fresh()->email)->toBe('same@example.com');
});

test('a contact on another team list cannot be edited', function () {
    $user = User::factory()->create();
    $list = listForUser($user);
    $foreignContact = Contact::factory()->create();

    $this->actingAs($user);

    Livewire::test('pages::lists.show', ['contactList' => $list])
        ->call('editEmail', $foreignContact)
        ->assertForbidden();
});

test('the contact rows render a gravatar url hashed from the email', function () {
    $user = User::factory()->create();
    $list = listForUser($user);
    $contact = Contact::factory()->create([
        'team_id' => $list->team_id,
        'contact_list_id' => $list->id,
        'email' => 'ada@example.com',
    ]);

    $this->actingAs($user);

    Livewire::test('pages::lists.show', ['contactList' => $list])
        ->assertSee(hash('sha256', 'ada@example.com'))
        ->assertSee('d=404');

    expect($contact->gravatarUrl(64))
        ->toContain('https://gravatar.com/avatar/'.hash('sha256', 'ada@example.com'))
        ->toContain('s=64');
});

test('the gravatar hash ignores email casing and whitespace', function () {
    $contact = Contact::factory()->make(['email' => '  Ada@Example.com ']);

    expect($contact->gravatarUrl())->toContain(hash('sha256', 'ada@example.com'));
});

test('initials fall back to the email when the contact has only a placeholder name', function () {
    expect(Contact::factory()->make(['first_name' => 'Ada', 'last_name' => 'Lovelace'])->initials())->toBe('AL')
        ->and(Contact::factory()->make([
            'first_name' => Contact::DEFAULT_FIRST_NAME,
            'last_name' => null,
            'email' => 'grace.hopper@example.com',
        ])->initials())->toBe('GH');
});
