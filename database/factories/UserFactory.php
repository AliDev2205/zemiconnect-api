<?php

namespace Database\Factories;

use App\Enums\UserStatus;
use App\Models\AgentProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /** Mot de passe des comptes de test (conforme à la règle : 10 caractères, lettre, chiffre). */
    public const PASSWORD = 'motdepasse2026';

    protected static ?string $password;

    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => strtoupper(fake()->lastName()),
            'email' => fake()->unique()->safeEmail(),
            'phone' => null,
            'password' => static::$password ??= self::PASSWORD,
            'status' => UserStatus::Active,
            'must_change_password' => false,
            'remember_token' => Str::random(10),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => UserStatus::Inactive]);
    }

    public function archived(): static
    {
        return $this->state(fn () => ['status' => UserStatus::Archived, 'archived_at' => now()]);
    }

    public function mustChangePassword(): static
    {
        return $this->state(fn () => ['must_change_password' => true]);
    }

    public function role(string $role): static
    {
        return $this->afterCreating(function (User $user) use ($role): void {
            $user->assignRole($role);

            if ($role === 'agent') {
                AgentProfile::query()->create([
                    'user_id' => $user->id,
                    'matricule' => 'AG-'.Str::upper(Str::random(6)),
                    'organization' => 'Structure de test',
                ]);
            }
        });
    }
}
