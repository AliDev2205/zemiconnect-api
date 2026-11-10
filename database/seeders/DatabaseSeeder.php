<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            FirstAdminSeeder::class,
        ]);

        // Données de démonstration : jamais hors de l'environnement local.
        if (app()->environment('local')) {
            $this->call(DemoDataSeeder::class);
        }
    }
}
