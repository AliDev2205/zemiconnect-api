<?php

namespace Tests\Feature\Auth;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * /auth/me, /auth/logout, EnsureAccountIsActive et EnsurePasswordChanged.
 */
class SessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_me_sans_session_401(): void
    {
        $this->getJson('/api/v1/auth/me')
            ->assertUnauthorized()
            ->assertJson(['status' => 'error', 'message' => 'Non authentifié.']);
    }

    public function test_me_renvoie_utilisateur_et_permissions(): void
    {
        $user = $this->userWithRole('admin');

        $response = $this->actingAs($user)->getJson('/api/v1/auth/me');

        $response->assertOk()
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.role', 'admin')
            ->assertJsonPath('data.user.matricule', null);
        $this->assertContains('settings.manage', $response->json('data.permissions'));
        $this->assertNotContains('drivers.manage', $response->json('data.permissions'));
    }

    public function test_compte_desactive_en_cours_de_session_403_et_deconnecte(): void
    {
        $user = $this->userWithRole('agent');
        $this->actingAs($user)->getJson('/api/v1/auth/me')->assertOk();

        User::query()->whereKey($user->id)->update(['status' => UserStatus::Inactive->value]);
        $user->refresh();

        $this->actingAs($user)->getJson('/api/v1/auth/me')
            ->assertForbidden()
            ->assertJsonPath('code', 'account_disabled');
        $this->assertGuest('web');
    }

    public function test_deconnexion_204_et_session_fermee(): void
    {
        $user = $this->userWithRole('gestionnaire', ['email' => 'g@test.local']);
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'motdepasse2026'])->assertOk();

        $this->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->assertGuest('web');

        // Entre deux requêtes d'un même test, l'application garde les gardes résolus
        // (utilisateur en mémoire) : on les vide comme le ferait une nouvelle requête HTTP.
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_deconnexion_sans_session_401(): void
    {
        $this->postJson('/api/v1/auth/logout')->assertUnauthorized();
    }

    public function test_mot_de_passe_provisoire_seuls_me_password_et_logout_passent(): void
    {
        $this->seedRoles();
        $user = User::factory()->mustChangePassword()->role('agent')->create();

        $this->actingAs($user)->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.user.must_change_password', true);

        $this->actingAs($user)->putJson('/api/v1/auth/profile', ['first_name' => 'A', 'last_name' => 'B'])
            ->assertForbidden()
            ->assertJsonPath('code', 'password_change_required');

        $this->actingAs($user)->postJson('/api/v1/auth/logout')->assertNoContent();
    }

    public function test_reponse_404_au_format_commun(): void
    {
        $this->getJson('/api/v1/inexistant')
            ->assertNotFound()
            ->assertJson(['status' => 'error', 'message' => 'Ressource introuvable.']);
    }
}
