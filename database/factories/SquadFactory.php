<?php

namespace Database\Factories;

use App\Models\Squad;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Squad>
 */
class SquadFactory extends Factory
{
    protected $model = Squad::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(2, true).' Squad';

        return [
            'name' => ucfirst($name),
            'slug' => Squad::uniqueSlug($name),
            'owner_id' => User::factory(),
        ];
    }
}
