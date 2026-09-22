<?php

use App\Enums\ContactStatus;
use App\Enums\IntegrationProvider;
use App\Integrations\Drivers\GoToWebinarProvider;
use App\Integrations\Drivers\SendPulseProvider;
use App\Integrations\Drivers\ZohoCampaignsProvider;
use App\Jobs\PushDeliveryContact;
use App\Models\Contact;
use App\Models\ContactList;
use App\Models\Delivery;
use App\Models\DeliveryContact;
use App\Models\Integration;
use App\Models\Team;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Cache\RateLimiting\Unlimited;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

/**
 * A pending delivery contact aimed at an integration for the given provider.
 */
function pendingPushFor(IntegrationProvider $provider): DeliveryContact
{
    $team = Team::factory()->create();

    $integration = Integration::factory()->create([
        'team_id' => $team->id,
        'provider' => $provider,
        'credentials' => $provider->usesOAuth()
            ? [
                'access_token' => 'test-token',
                'refresh_token' => 'test-refresh',
                'expires_at' => now()->addHour()->toIso8601String(),
            ]
            : ['api_key' => 'test-key'],
    ]);

    $list = ContactList::factory()->create(['team_id' => $team->id]);
    $contact = Contact::factory()->create(['team_id' => $team->id, 'contact_list_id' => $list->id]);

    $delivery = Delivery::factory()->create([
        'team_id' => $team->id,
        'contact_list_id' => $list->id,
        'integration_id' => $integration->id,
        'remote_id' => 'listkey123',
        'status' => Delivery::STATUS_PROCESSING,
        'total_count' => 1,
    ]);

    return DeliveryContact::factory()->create([
        'delivery_id' => $delivery->id,
        'contact_id' => $contact->id,
        'status' => ContactStatus::Pending,
    ]);
}

test('zoho pushes are capped at the documented call rate per integration', function () {
    $deliveryContact = pendingPushFor(IntegrationProvider::ZohoCampaigns);

    $limit = RateLimiter::limiter('autoresponder-push')(new PushDeliveryContact($deliveryContact));

    expect($limit)->not->toBeInstanceOf(Unlimited::class)
        ->and($limit->maxAttempts)->toBe(ZohoCampaignsProvider::CALLS_PER_MINUTE_LIMIT)
        ->and($limit->decaySeconds)->toBe(60)
        ->and($limit->key)->toContain((string) $deliveryContact->delivery->integration_id);
});

test('gotowebinar pushes are capped at goto documented per-second rate per integration', function () {
    $deliveryContact = pendingPushFor(IntegrationProvider::GoToWebinar);

    $limit = RateLimiter::limiter('autoresponder-push')(new PushDeliveryContact($deliveryContact));

    expect($limit)->not->toBeInstanceOf(Unlimited::class)
        ->and($limit->maxAttempts)->toBe(GoToWebinarProvider::CALLS_PER_SECOND_LIMIT)
        ->and($limit->decaySeconds)->toBe(1)
        ->and($limit->key)->toContain((string) $deliveryContact->delivery->integration_id);
});

test('sendpulse pushes are capped at its hard per-second rate per integration', function () {
    $deliveryContact = pendingPushFor(IntegrationProvider::SendPulse);

    $limit = RateLimiter::limiter('autoresponder-push')(new PushDeliveryContact($deliveryContact));

    expect($limit)->not->toBeInstanceOf(Unlimited::class)
        ->and($limit->maxAttempts)->toBe(SendPulseProvider::CALLS_PER_SECOND_LIMIT)
        ->and($limit->decaySeconds)->toBe(1)
        ->and($limit->key)->toContain((string) $deliveryContact->delivery->integration_id);
});

test('pushes to providers without a documented cap are not rate limited', function () {
    $deliveryContact = pendingPushFor(IntegrationProvider::GetResponse);

    $limit = RateLimiter::limiter('autoresponder-push')(new PushDeliveryContact($deliveryContact));

    expect($limit)->toBeInstanceOf(Unlimited::class);
});

test('a push over the limit is released untouched rather than sent', function () {
    // A one-per-minute stand-in for Zoho's real cap keeps the test cheap while
    // exercising the same limiter name the job's middleware resolves.
    RateLimiter::for('autoresponder-push', fn (): Limit => Limit::perMinute(1)->by('zoho-test'));

    Http::fake(['campaigns.zoho.com/api/v1.1/json/listsubscribe' => Http::response([
        'status' => 'success',
        'code' => '0',
    ], 200)]);

    $first = pendingPushFor(IntegrationProvider::ZohoCampaigns);
    $second = pendingPushFor(IntegrationProvider::ZohoCampaigns);

    PushDeliveryContact::dispatchSync($first);
    PushDeliveryContact::dispatchSync($second);

    expect($first->fresh()->status)->toBe(ContactStatus::Synced)
        ->and($second->fresh()->status)->toBe(ContactStatus::Pending);

    Http::assertSentCount(1);
});
