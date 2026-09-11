<?php

namespace Database\Factories;

use App\Enums\SuppressionReason;
use App\Models\Suppression;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Suppression>
 */
class SuppressionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'user_id' => null,
            'email' => Suppression::normalize(fake()->unique()->safeEmail()),
            'reason' => SuppressionReason::Unsubscribed,
            'removed_count' => 0,
        ];
    }

    /**
     * A block added because the address bounced.
     */
    public function bounced(): static
    {
        return $this->state(fn (array $attributes): array => [
            'reason' => SuppressionReason::Bounced,
        ]);
    }
}
