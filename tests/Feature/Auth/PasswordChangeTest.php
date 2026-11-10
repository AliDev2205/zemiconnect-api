<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    private function change(User $user, array $payload)
    {
        return $this->actingAs($user)->putJson('/api/v1/auth/password', $payload + [
            'current_password' => 'motdepasse2026',
            'password_confirmation' => $payload['password'] ?? null,
        ]);
    }

    public function test_changement_obligatoire_leve_apres_changement(): void
    {
        $this->seedRoles();
        $user = User::factory()->mustChangePassword()->role('agent')->create();

        $this->change($user, ['password' => 'nouveau2026mdp'])->assertOk()->assertJsonPath('data', null);

        $user->refresh();
        $this->assertFalse($user->must_change_password);
        $this->assertTrue(Hash::check('nouveau2026mdp', $user->password));

        // Le reste de l'API redevient accessible.
        $this->actingAs($user)->putJson('/api/v1/auth/profile', ['first_name' => 'A', 'last_name' => 'B'])->assertOk();
    }

    public function test_mot_de_passe_actuel_incorrect_422(): void
    {
        $user = $this->userWithRole('gestionnaire');

        $this->change($user, ['current_password' => 'pas-le-bon-1', 'password' => 'nouveau2026mdp'])
            ->assertStatus(422)
            ->assertJsonPath('errors.current_password.0', 'Le mot de passe actuel est incorrect.');
    }

    public function test_confirmation_differente_422(): void
    {
        $user = $this->userWithRole('gestionnaire');

        $this->change($user, ['password' => 'nouveau2026mdp', 'password_confirmation' => 'autre2026mdp'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    /**
     * Règles identiques au frontend : 10 caractères au moins, une lettre, un chiffre.
     */
    public function test_regles_du_mot_de_passe(): void
    {
        $user = $this->userWithRole('gestionnaire');

        foreach (['court12', 'sanschiffreici', '1234567890'] as $weak) {
            $this->change($user, ['password' => $weak])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['password']);
        }

        $this->change($user, ['password' => 'abcdefghi1'])->assertOk();
    }

    public function test_les_autres_sessions_sont_deconnectees(): void
    {
        $user = $this->userWithRole('gestionnaire');
        $other = $this->userWithRole('agent');
        DB::table('sessions')->insert([
            ['id' => 'autre-appareil-1', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()],
            ['id' => 'autre-appareil-2', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()],
            ['id' => 'autre-compte', 'user_id' => $other->id, 'payload' => '', 'last_activity' => time()],
        ]);
        $oldRememberToken = $user->remember_token;

        $this->change($user, ['password' => 'nouveau2026mdp'])->assertOk();

        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->whereLike('id', 'autre-appareil-%')->count());
        $this->assertSame(1, DB::table('sessions')->where('id', 'autre-compte')->count());
        $this->assertNotSame($oldRememberToken, $user->fresh()->remember_token);
        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_non_connecte_401(): void
    {
        $this->putJson('/api/v1/auth/password', [])->assertUnauthorized();
    }
}
