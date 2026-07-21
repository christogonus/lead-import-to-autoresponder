<?php

namespace Database\Factories;

use App\Models\ContactList;
use App\Models\Import;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Import>
 */
class ImportFactory extends Factory
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
            'user_id' => User::factory(),
            'source' => 'csv',
            'filename' => fake()->word().'.csv',
            'total_rows' => 0,
            'imported_count' => 0,
            'skipped_count' => 0,
            'failed_count' => 0,
            'status' => 'completed',
        ];
    }
}
