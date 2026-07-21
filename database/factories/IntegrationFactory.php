<?php

namespace Database\Factories;

use App\Enums\IntegrationProvider;
use App\Models\Integration;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Integration>
 */
class IntegrationFactory extends Factory
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
            'provider' => IntegrationProvider::GetResponse,
            'name' => fake()->company().' GetResponse',
            'credentials' => ['api_key' => Str::random(32)],
            'status' => 'connected',
            'last_verified_at' => now(),
        ];
    }

    /**
     * Indicate that the integration's credentials are invalid.
     */
    public function invalid(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'invalid',
            'last_verified_at' => null,
        ]);
    }
}
