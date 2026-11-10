<?php

namespace Database\Factories;

use App\Enums\DriverStatus;
use App\Models\Driver;
use App\Models\User;
use App\Services\ZemiNumberGenerator;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Driver>
 */
class DriverFactory extends Factory
{
    public function definition(): array
    {
        return [
            'last_name' => strtoupper(fake()->lastName()),
            'first_name' => fake()->firstName(),
            'phone' => '+229 01 '.fake()->numerify('## ## ## ##'),
            'status' => DriverStatus::EnAttente,
            'created_by' => User::factory(),
        ];
    }

    /** Dossier validé : numéro attribué et date de validation (CHECK de la base). */
    public function active(): static
    {
        return $this->state(fn () => [
            'status' => DriverStatus::Actif,
            'zemi_number' => app(ZemiNumberGenerator::class)->generate(),
            'validated_at' => now(),
        ]);
    }
}
