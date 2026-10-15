<?php

use App\Models\User;
use Laravel\Paddle\Subscription;

function subscriptionCreatedPayload(string $customerId, string $priceId = 'pri_monthly_test'): array
{
    return [
        'event_type' => 'subscription.created',
        'data' => [
            'id' => 'sub_01webhook',
            'customer_id' => $customerId,
            'status' => Subscription::STATUS_ACTIVE,
            'next_billed_at' => now()->addMonth()->toIso8601String(),
            'custom_data' => null,
            'items' => [[
                'status' => 'active',
                'quantity' => 1,
                'price' => ['id' => $priceId, 'product_id' => 'pro_lead2crm'],
            ]],
        ],
    ];
}

test('a subscription created by Paddle unlocks the workspace', function () {
    $user = User::factory()->unsubscribed()->create();
    $user->customer()->create(['paddle_id' => 'ctm_webhook', 'name' => $user->name, 'email' => $user->email]);

    $this->postJson('/paddle/webhook', subscriptionCreatedPayload('ctm_webhook'))->assertOk();

    expect($user->fresh()->hasActiveSubscription())->toBeTrue();

    $this->actingAs($user->fresh())->get(route('dashboard'))->assertOk();
});

test('webhooks are refused on a live server without a signing secret', function () {
    app()->detectEnvironment(fn () => 'production');

    $user = User::factory()->unsubscribed()->create();
    $user->customer()->create(['paddle_id' => 'ctm_forged', 'name' => $user->name, 'email' => $user->email]);

    $this->postJson('/paddle/webhook', subscriptionCreatedPayload('ctm_forged'))
        ->assertStatus(503);

    expect($user->fresh()->hasActiveSubscription())->toBeFalse();
});
