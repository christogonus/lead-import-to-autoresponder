<?php

use App\Actions\Users\DeleteUser;
use App\Enums\TeamRole;
use App\Models\Contact;
use App\Models\ContactList;
use App\Models\Integration;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Laravel\Paddle\Cashier;
use Laravel\Paddle\Exceptions\PaddleException;
use Livewire\Livewire;

function fakePaddleCancellation(): void
{
    Cashier::fake(['subscriptions/*/cancel' => ['data' => [
        'status' => 'canceled',
        'canceled_at' => now()->toIso8601String(),
    ]]]);
}

test('a user can download everything held about them', function () {
    $user = User::factory()->create(['email' => 'owner@example.com']);
    $team = $user->currentTeam;
    $list = ContactList::factory()->create(['team_id' => $team->id, 'name' => 'Webinar signups']);
    Contact::factory()->create(['team_id' => $team->id, 'contact_list_id' => $list->id, 'email' => 'lead@example.com']);
    Integration::factory()->create(['team_id' => $team->id, 'credentials' => ['api_key' => 'super-secret-key']]);

    $otherOwner = User::factory()->create();
    $otherOwner->currentTeam->members()->attach($user, ['role' => TeamRole::Member->value]);
    ContactList::factory()->create(['team_id' => $otherOwner->currentTeam->id, 'name' => 'Not yours']);

    $response = $this->actingAs($user)->get(route('privacy.export'));

    $response->assertOk()->assertDownload();

    $export = json_decode($response->streamedContent(), true, flags: JSON_THROW_ON_ERROR);

    expect($export['account']['email'])->toBe('owner@example.com')
        ->and($export['billing']['subscriptions'])->toHaveCount(1)
        ->and($export['teams'])->toHaveCount(2)
        ->and($export['teams'][0]['lists'][0]['name'])->toBe('Webinar signups')
        ->and($export['teams'][0]['lists'][0]['contacts'][0]['email'])->toBe('lead@example.com')
        ->and($export['teams'][0]['integrations'])->toHaveCount(1)
        ->and($export['teams'][1])->not->toHaveKey('lists')
        ->and($response->streamedContent())->not->toContain('super-secret-key')
        ->and($response->streamedContent())->not->toContain('Not yours');
});

test('the export needs a signed-in user', function () {
    $this->get(route('privacy.export'))->assertRedirect(route('login'));
});

test('deleting an account cancels billing and erases the teams it owns', function () {
    fakePaddleCancellation();

    $user = User::factory()->create();
    $ownedTeam = $user->currentTeam;
    $list = ContactList::factory()->create(['team_id' => $ownedTeam->id]);
    Contact::factory()->count(3)->create(['team_id' => $ownedTeam->id, 'contact_list_id' => $list->id]);

    $otherOwner = User::factory()->create();
    $sharedTeam = $otherOwner->currentTeam;
    $sharedTeam->members()->attach($user, ['role' => TeamRole::Admin->value]);

    app(DeleteUser::class)->handle($user);

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/cancel')
        && $request['effective_from'] === 'immediately');

    expect(User::find($user->id))->toBeNull()
        ->and(Team::withTrashed()->find($ownedTeam->id))->toBeNull()
        ->and(Contact::where('team_id', $ownedTeam->id)->count())->toBe(0)
        ->and($user->subscriptions()->count())->toBe(0)
        ->and(Team::find($sharedTeam->id))->not->toBeNull();
});

test('nothing is deleted when Paddle cannot cancel the subscription', function () {
    Cashier::fake()->error('subscriptions/*/cancel', 'Paddle is down');

    $user = User::factory()->create();

    expect(fn () => app(DeleteUser::class)->handle($user))->toThrow(PaddleException::class);

    expect(User::find($user->id))->not->toBeNull()
        ->and(Team::find($user->currentTeam->id))->not->toBeNull();
});

test('the settings page explains a failed deletion instead of erroring', function () {
    Cashier::fake()->error('subscriptions/*/cancel', 'Paddle is down');

    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test('pages::settings.delete-user-modal')
        ->set('password', 'password')
        ->call('deleteUser')
        ->assertHasErrors(['password']);

    expect($user->fresh())->not->toBeNull();
});

test('deleted teams are erased once their retention window passes', function () {
    $expired = Team::factory()->create(['deleted_at' => now()->subDays(Team::RETENTION_DAYS_AFTER_DELETION + 1)]);
    $recent = Team::factory()->create(['deleted_at' => now()->subDays(2)]);
    $list = ContactList::factory()->create(['team_id' => $expired->id]);
    Contact::factory()->create(['team_id' => $expired->id, 'contact_list_id' => $list->id]);

    Artisan::call('model:prune', ['--model' => [Team::class]]);

    expect(Team::withTrashed()->find($expired->id))->toBeNull()
        ->and(Contact::where('team_id', $expired->id)->count())->toBe(0)
        ->and(Team::withTrashed()->find($recent->id))->not->toBeNull();
});
