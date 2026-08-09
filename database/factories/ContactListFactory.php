<?php

namespace Database\Factories;

use App\Models\ContactList;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContactList>
 */
class ContactListFactory extends Factory
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
            'name' => fake()->words(2, true),
            'drafted_at' => null,
        ];
    }

    /**
     * A list that has been set aside as a draft.
     */
    public function drafted(): static
    {
        return $this->state(fn (array $attributes): array => [
            'drafted_at' => now(),
        ]);
    }
}
