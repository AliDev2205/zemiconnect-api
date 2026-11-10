<?php

namespace Database\Factories;

use App\Models\Motorcycle;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Motorcycle>
 */
class MotorcycleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'plate_number' => 'BB'.fake()->unique()->numerify('####').'RB',
            'brand' => 'Bajaj',
            'color' => 'Rouge',
            'created_by' => User::factory(),
        ];
    }
}
