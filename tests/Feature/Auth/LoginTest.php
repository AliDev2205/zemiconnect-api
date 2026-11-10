<?php

namespace Tests\Feature\Auth;

use App\Models\Activity;
use App\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Auth\SessionGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    private function login(string $email, string $password = UserFactory::PASSWORD, bool $remember = false)
    {
        return $this->postJson('/api/v1/auth/login', compact('email', 'password', 'remember'));
    }

    private function lastLoginLog(): ?Activity
    {
        return Activity::query()->where('log_name', 'connexion')->latest('id')->first();
    }

    public function test_connexion_reussie_renvoie_me_et_ouvre_la_session(): void
    {
        $user = $this->userWithRole('gestionnaire', ['email' => 'aicha@test.local']);

        $response = $this->login('aicha@test.local');

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.email', 'aicha@test.local')
            ->assertJsonPath('data.user.role', 'gestionnaire')
            ->assertJsonPath('data.user.status', 'active')
            ->assertJsonPath('data.user.must_change_password', false)
            ->assertJsonStructure(['data' => ['user' => ['first_name', 'last_name', 'phone', 'last_login_at', 'created_at', 'matricule', 'organization'], 'permissions']]);

        $this->assertContains('drivers.manage', $response->json('data.permissions'));
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_connexion_reussie_journalisee(): void
    {
        $user = $this->userWithRole('agent', ['email' => 'agent@test.local']);

        $this->login('agent@test.local')->assertOk();

        $log = $this->lastLoginLog();
        $this->assertSame('auth.login', $log->event);
        $this->assertSame('reussie', $log->properties['result']);
        $this->assertSame('agent@test.local', $log->properties['email']);
        $this->assertSame($user->id, $log->causer_id);
        $this->assertSame('127.0.0.1', $log->ip_address);
    }

    public function test_agent_recoit_matricule_et_structure(): void
    {
        $this->userWithRole('agent', ['email' => 'agent@test.local']);

        $this->login('agent@test.local')
            ->assertOk()
            ->assertJsonPath('data.user.role', 'agent')
            ->assertJsonPath('data.user.organization', 'Structure de test')
            ->assertJsonPath('data.permissions', ['drivers.search_exact', 'drivers.view_professional']);
    }

    public function test_mauvais_mot_de_passe_message_unique_et_journalise(): void
    {
        $this->userWithRole('gestionnaire', ['email' => 'aicha@test.local']);

        $this->login('aicha@test.local', 'mauvais-mot-2026')
            ->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'Identifiants incorrects.')
            ->assertJsonPath('errors.email.0', 'Identifiants incorrects.')
            ->assertJsonMissingPath('errors.password');

        $this->assertGuest('web');
        $this->assertSame('echouee', $this->lastLoginLog()->properties['result']);
    }

    public function test_email_inconnu_meme_reponse_que_mauvais_mot_de_passe(): void
    {
        $this->userWithRole('gestionnaire', ['email' => 'aicha@test.local']);

        $unknown = $this->login('inconnu@test.local')->assertStatus(422)->json();
        $wrong = $this->login('aicha@test.local', 'mauvais-mot-2026')->assertStatus(422)->json();

        $this->assertSame($wrong, $unknown);
        $this->assertSame('echouee', $this->lastLoginLog()->properties['result']);
    }

    public function test_champs_obligatoires(): void
    {
        $this->postJson('/api/v1/auth/login', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_blocage_apres_cinq_echecs_429_avec_retry_after(): void
    {
        $this->userWithRole('gestionnaire', ['email' => 'aicha@test.local']);

        for ($i = 0; $i < 5; $i++) {
            $this->login('aicha@test.local', 'mauvais-mot-2026')->assertStatus(422);
        }

        $response = $this->login('aicha@test.local', 'mauvais-mot-2026');

        $response->assertStatus(429)->assertJsonPath('status', 'error');
        $retryAfter = (int) $response->headers->get('Retry-After');
        $this->assertGreaterThan(0, $retryAfter);
        $this->assertLessThanOrEqual(15 * 60, $retryAfter);
        $this->assertSame('bloquee', $this->lastLoginLog()->properties['result']);
    }

    public function test_bloque_meme_avec_le_bon_mot_de_passe_pendant_le_blocage(): void
    {
        $this->userWithRole('gestionnaire', ['email' => 'aicha@test.local']);

        for ($i = 0; $i < 5; $i++) {
            $this->login('aicha@test.local', 'mauvais-mot-2026');
        }

        $this->login('aicha@test.local')->assertStatus(429)->assertHeader('Retry-After');
        $this->assertGuest('web');
    }

    public function test_le_blocage_est_leve_apres_quinze_minutes(): void
    {
        $this->userWithRole('gestionnaire', ['email' => 'aicha@test.local']);

        for ($i = 0; $i < 5; $i++) {
            $this->login('aicha@test.local', 'mauvais-mot-2026');
        }

        $this->travel(15)->minutes();
        $this->travel(1)->seconds();

        $this->login('aicha@test.local')->assertOk();
    }

    public function test_une_connexion_reussie_remet_le_compteur_a_zero(): void
    {
        $this->userWithRole('gestionnaire', ['email' => 'aicha@test.local']);

        for ($i = 0; $i < 4; $i++) {
            $this->login('aicha@test.local', 'mauvais-mot-2026');
        }
        $this->login('aicha@test.local')->assertOk();
        $this->postJson('/api/v1/auth/logout')->assertNoContent();

        for ($i = 0; $i < 4; $i++) {
            $this->login('aicha@test.local', 'mauvais-mot-2026')->assertStatus(422);
        }
    }

    public function test_compte_desactive_403_account_disabled(): void
    {
        $this->seedRoles();
        User::factory()->inactive()->role('agent')->create(['email' => 'off@test.local']);

        $this->login('off@test.local')
            ->assertForbidden()
            ->assertJsonPath('code', 'account_disabled');

        $this->assertGuest('web');
        $log = $this->lastLoginLog();
        $this->assertSame('echouee', $log->properties['result']);
        $this->assertSame('account_disabled', $log->properties['reason']);
    }

    public function test_compte_archive_403_account_disabled(): void
    {
        $this->seedRoles();
        User::factory()->archived()->role('agent')->create(['email' => 'old@test.local']);

        $this->login('old@test.local')->assertForbidden()->assertJsonPath('code', 'account_disabled');
    }

    public function test_compte_desactive_avec_mauvais_mot_de_passe_ne_revele_rien(): void
    {
        $this->seedRoles();
        User::factory()->inactive()->role('agent')->create(['email' => 'off@test.local']);

        $this->login('off@test.local', 'mauvais-mot-2026')->assertStatus(422)->assertJsonMissingPath('code');
    }

    public function test_rester_connecte_pose_le_cookie_de_session_longue(): void
    {
        $this->userWithRole('agent', ['email' => 'agent@test.local']);
        /** @var SessionGuard $guard */
        $guard = Auth::guard('web');

        $this->login('agent@test.local', remember: true)->assertOk()->assertCookie($guard->getRecallerName());
    }

    public function test_sans_rester_connecte_pas_de_cookie_de_session_longue(): void
    {
        $this->userWithRole('agent', ['email' => 'agent@test.local']);
        /** @var SessionGuard $guard */
        $guard = Auth::guard('web');

        $this->login('agent@test.local')->assertOk()->assertCookieMissing($guard->getRecallerName());
    }

    public function test_duree_de_la_session_longue_configuree(): void
    {
        $this->assertSame(30 * 24 * 60, config('auth.guards.web.remember'));
    }
}
