<?php

namespace Tests;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    /** Origine du frontend : rend les requêtes « stateful » pour Sanctum (session par cookie). */
    public const FRONTEND = 'http://localhost:3000';

    protected function setUp(): void
    {
        parent::setUp();

        // Aucun appel réseau pendant les tests.
        Http::preventStrayRequests();
        $this->fakePwnedPasswords();

        $this->withHeaders([
            'Accept' => 'application/json',
            'Origin' => self::FRONTEND,
            'Referer' => self::FRONTEND.'/',
        ]);
    }

    /**
     * Service des mots de passe compromis : « aucun mot de passe connu ».
     * Surchargée par UncompromisedPasswordTest (Http::fake garde la première règle qui correspond).
     */
    protected function fakePwnedPasswords(): void
    {
        Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);
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
