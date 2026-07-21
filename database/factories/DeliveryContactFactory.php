<?php

namespace Database\Factories;

use App\Enums\ContactStatus;
use App\Models\Contact;
use App\Models\Delivery;
use App\Models\DeliveryContact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeliveryContact>
 */
class DeliveryContactFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'delivery_id' => Delivery::factory(),
            'contact_id' => Contact::factory(),
            'status' => ContactStatus::Pending,
            'remote_id' => null,
            'sync_error' => null,
            'synced_at' => null,
        ];
    }

    /**
     * Indicate that the contact was successfully delivered.
     */
    public function synced(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ContactStatus::Synced,
            'remote_id' => fake()->uuid(),
            'synced_at' => now(),
        ]);
    }

    /**
     * Indicate that the contact failed to deliver.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ContactStatus::Failed,
            'sync_error' => 'The provider rejected the contact.',
        ]);
    }
}
