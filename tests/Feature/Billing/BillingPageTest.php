<?php

use App\Enums\BillingPlan;
use App\Models\User;
use Database\Factories\UserFactory;
use Laravel\Paddle\Cashier;
use Laravel\Paddle\Subscription;
use Livewire\Livewire;

function fakePaddleCustomer(User $user): void
{
    Cashier::fake([
        'customers*' => ['data' => [['id' => 'ctm_test123', 'name' => $user->name, 'email' => $user->email]]],
    ]);
}

test('choosing a plan opens Paddle checkout for that price', function () {
    $user = User::factory()->unsubscribed()->create();
    fakePaddleCustomer($user);

    $this->actingAs($user);

    Livewire::test('pages::billing.show')
        ->call('checkout', 'yearly')
        ->assertDispatched('open-paddle-checkout', function (string $event, array $params): bool {
            return $params['options']['items'] === [['priceId' => 'pri_yearly_test', 'quantity' => 1]]
                && $params['options']['customer'] === ['id' => 'ctm_test123']
                && $params['options']['settings']['displayMode'] === 'overlay'
                && str_contains($params['options']['settings']['successUrl'], 'welcome=1');
        });

    expect($user->fresh()->customer->paddle_id)->toBe('ctm_test123');
});

test('an unknown plan does not open checkout', function () {
    $user = User::factory()->unsubscribed()->create();

    $this->actingAs($user);

    Livewire::test('pages::billing.show')
        ->call('checkout', 'lifetime')
        ->assertNotDispatched('open-paddle-checkout');
});

test('checkout is not offered until Paddle is configured', function () {
    config(['services.paddle.prices.yearly' => null]);

    $user = User::factory()->unsubscribed()->create();

    $this->actingAs($user);

    expect(BillingPlan::checkoutIsConfigured())->toBeFalse();

    Livewire::test('pages::billing.show')
        ->assertSee('Checkout is being set up')
        ->call('checkout', 'monthly')
        ->assertNotDispatched('open-paddle-checkout');
});

test('subscribers are not sent through checkout twice', function () {
    $user = User::factory()->create();
    Cashier::fake(['subscriptions/*' => ['data' => ['next_billed_at' => null]]]);

    $this->actingAs($user);

    Livewire::test('pages::billing.show')
        ->call('checkout', 'monthly')
        ->assertNotDispatched('open-paddle-checkout');
});

test('returning from checkout waits for the webhook, then moves on', function () {
    $user = User::factory()->unsubscribed()->create();

    $this->actingAs($user);

    $page = Livewire::withQueryParams(['welcome' => 1])
        ->test('pages::billing.show')
        ->assertSee('Setting up your account')
        ->call('checkForSubscription')
        ->assertNoRedirect();

    UserFactory::subscribe($user);

    $page->call('checkForSubscription')
        ->assertRedirect(route('dashboard', ['current_team' => $user->currentTeam->slug]));
});

test('subscribers see their plan', function () {
    $user = User::factory()->unsubscribed()->create();
    UserFactory::subscribe($user, BillingPlan::Yearly);
    Cashier::fake(['subscriptions/*' => ['data' => ['next_billed_at' => null]]]);

    $this->actingAs($user)
        ->get(route('billing.show'))
        ->assertOk()
        ->assertSee('Lead2Crm Yearly')
        ->assertSee('$204 billed once a year');
});

test('cancelling schedules the end of the subscription at Paddle', function () {
    $user = User::factory()->create();
    $endsAt = now()->addDays(12)->startOfSecond();

    // The specific endpoint first: the wildcard would otherwise answer it.
    Cashier::fake([
        'subscriptions/*/cancel' => ['data' => [
            'status' => 'active',
            'scheduled_change' => ['action' => 'cancel', 'effective_at' => $endsAt->toIso8601String()],
        ]],
        'subscriptions/*' => ['data' => ['next_billed_at' => null]],
    ]);

    $this->actingAs($user);

    Livewire::test('pages::billing.show')->call('cancel');

    $subscription = $user->fresh()->subscription();

    expect($subscription->onGracePeriod())->toBeTrue()
        ->and($subscription->ends_at->equalTo($endsAt))->toBeTrue()
        ->and($user->fresh()->hasActiveSubscription())->toBeTrue();
});

test('a pending cancellation can be undone', function () {
    $user = User::factory()->create();
    $user->subscription()->update(['ends_at' => now()->addDays(5)]);

    Cashier::fake(['subscriptions/*' => ['data' => ['status' => 'active', 'next_billed_at' => null]]]);

    $this->actingAs($user);

    Livewire::test('pages::billing.show')->call('resume');

    expect($user->fresh()->subscription()->ends_at)->toBeNull();
});

test('switching to yearly swaps the Paddle price', function () {
    $user = User::factory()->create();

    Cashier::fake(['subscriptions/*' => ['data' => [
        'status' => 'active',
        'next_billed_at' => null,
        'items' => [['status' => 'active', 'quantity' => 1, 'price' => ['id' => 'pri_yearly_test', 'product_id' => 'pro_lead2crm']]],
    ]]]);

    $this->actingAs($user);

    Livewire::test('pages::billing.show')->call('switchTo', 'yearly');

    expect($user->fresh()->currentPlan())->toBe(BillingPlan::Yearly);
});

test('a Paddle outage leaves the subscription untouched', function () {
    $user = User::factory()->create();

    Cashier::fake()->error('subscriptions/*/cancel', 'Service unavailable');
    Cashier::fake(['subscriptions/*' => ['data' => ['next_billed_at' => null]]]);

    $this->actingAs($user);

    Livewire::test('pages::billing.show')->call('cancel');

    expect($user->fresh()->subscription()->ends_at)->toBeNull();
});

test('the yearly plan is advertised at seventeen dollars a month', function () {
    expect(BillingPlan::Monthly->amountPerCycle())->toBe(24)
        ->and(BillingPlan::Yearly->monthlyRate())->toBe(17)
        ->and(BillingPlan::Yearly->amountPerCycle())->toBe(204)
        ->and(BillingPlan::Yearly->savingsPercent())->toBe(29)
        ->and(BillingPlan::fromPriceId('pri_yearly_test'))->toBe(BillingPlan::Yearly)
        ->and(Subscription::DEFAULT_TYPE)->toBe('default');
});

test('invoices are only served to the customer who paid', function () {
    $user = User::factory()->create();
    $stranger = User::factory()->create();

    $user->transactions()->create([
        'paddle_id' => 'txn_123',
        'invoice_number' => '325-10566',
        'status' => 'completed',
        'total' => '2400',
        'tax' => '0',
        'currency' => 'USD',
        'billed_at' => now(),
    ]);

    Cashier::fake(['transactions/txn_123/invoice' => ['data' => ['url' => 'https://paddle.test/invoice.pdf']]]);

    $this->actingAs($stranger)->get(route('billing.invoice', 'txn_123'))->assertNotFound();
    $this->actingAs($user)->get(route('billing.invoice', 'txn_123'))->assertRedirect('https://paddle.test/invoice.pdf');
});
