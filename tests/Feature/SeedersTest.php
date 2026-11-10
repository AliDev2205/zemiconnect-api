<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\FirstAdminSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class SeedersTest extends TestCase
{
    use RefreshDatabase;

    public function test_premier_administrateur_lu_dans_l_environnement_avec_changement_obligatoire(): void
    {
        $this->seed([RolesAndPermissionsSeeder::class, FirstAdminSeeder::class]);

        $admin = User::query()->where('email', 'admin@test.local')->firstOrFail();
        $this->assertTrue($admin->hasRole('admin'));
        $this->assertTrue($admin->must_change_password);
        $this->assertNull($admin->created_by);
        $this->assertTrue(Hash::check('Provisoire2026', $admin->password));
    }

    public function test_premier_administrateur_idempotent(): void
    {
        $this->seed([RolesAndPermissionsSeeder::class, FirstAdminSeeder::class, FirstAdminSeeder::class]);

        $this->assertSame(1, User::query()->where('email', 'admin@test.local')->count());
    }

    public function test_premier_administrateur_exige_email_et_mot_de_passe(): void
    {
        config(['zemiconnect.first_admin.password' => null]);

        $this->expectException(RuntimeException::class);
        $this->seed([RolesAndPermissionsSeeder::class, FirstAdminSeeder::class]);
    }

    public function test_donnees_de_demonstration_refusees_hors_local(): void
    {
        $this->expectException(RuntimeException::class);
        $this->seed(DemoDataSeeder::class);
    }
}
