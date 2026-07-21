<?php

namespace Database\Factories;

use App\Models\ContactList;
use App\Models\Delivery;
use App\Models\Integration;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Delivery>
 */
class DeliveryFactory extends Factory
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
            'contact_list_id' => ContactList::factory(),
            'integration_id' => Integration::factory(),
            'remote_id' => Str::random(6),
            'remote_name' => fake()->words(2, true),
            'status' => Delivery::STATUS_COMPLETED,
            'total_count' => 0,
            'synced_count' => 0,
            'failed_count' => 0,
        ];
    }
}
