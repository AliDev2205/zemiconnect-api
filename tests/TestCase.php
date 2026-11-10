<?php

namespace Tests;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** Origine du frontend : rend les requêtes « stateful » pour Sanctum (session par cookie). */
    public const FRONTEND = 'http://localhost:3000';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeaders([
            'Accept' => 'application/json',
            'Origin' => self::FRONTEND,
            'Referer' => self::FRONTEND.'/',
        ]);
    }

    protected function seedRoles(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /**
     * Compte actif avec un rôle (admin, gestionnaire, agent).
     */
    protected function userWithRole(string $role, array $attributes = []): User
    {
        $this->seedRoles();

        return User::factory()->role($role)->create($attributes);
    }
}
