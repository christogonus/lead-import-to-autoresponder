<?php

namespace Database\Factories;

use App\Enums\BillingPlan;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Paddle\Subscription;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ];
    }

    /**
     * Configure the model factory.
     *
     * Every user gets a personal team and, because the workspace sits behind a
     * paid plan, an active monthly subscription. Use unsubscribed() for anyone
     * who should meet the paywall.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (User $user) {
            $team = Team::factory()->personal()->create([
                'name' => $user->name."'s Team",
            ]);

            $team->members()->attach($user, [
                'role' => TeamRole::Owner->value,
            ]);

            $user->switchTeam($team);

            static::subscribe($user);
        });
    }

    /**
     * Indicate that the user has never paid, or their subscription has gone.
     */
    public function unsubscribed(): static
    {
        return $this->afterCreating(function (User $user) {
            $user->subscriptions()->each(function (Subscription $subscription) {
                $subscription->items()->delete();
                $subscription->delete();
            });
        });
    }

    /**
     * Give the user a subscription as Paddle's webhook would record it.
     */
    public static function subscribe(User $user, BillingPlan $plan = BillingPlan::Monthly, string $status = Subscription::STATUS_ACTIVE): Subscription
    {
        $subscription = $user->subscriptions()->create([
            'type' => Subscription::DEFAULT_TYPE,
            'paddle_id' => 'sub_'.Str::lower(Str::random(26)),
            'status' => $status,
        ]);

        $subscription->items()->create([
            'product_id' => 'pro_lead2crm',
            'price_id' => $plan->priceId() ?? 'pri_'.$plan->value,
            'status' => $status,
            'quantity' => 1,
        ]);

        return $subscription;
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the model has two-factor authentication configured.
     */
    public function withTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }
}
