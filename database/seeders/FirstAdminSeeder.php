<?php

namespace Database\Seeders;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Premier Administrateur (created_by nul), lu dans .env (FIRST_ADMIN_*).
 * Mot de passe provisoire : changement obligatoire à la première connexion (A10).
 * Idempotent : un compte existant n'est pas modifié.
 */
class FirstAdminSeeder extends Seeder
{
    public function run(): void
    {
        $config = config('zemiconnect.first_admin');

        if (empty($config['email']) || empty($config['password'])) {
            throw new RuntimeException('FIRST_ADMIN_EMAIL et FIRST_ADMIN_PASSWORD doivent être renseignés dans .env.');
        }

        $admin = User::query()->firstOrCreate(
            ['email' => strtolower($config['email'])],
            [
                'first_name' => $config['first_name'],
                'last_name' => $config['last_name'],
                'password' => $config['password'],
                'status' => UserStatus::Active,
                'must_change_password' => true,
            ],
        );

        $admin->syncRoles(['admin']);
    }
}
