<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_meme_reponse_que_l_email_existe_ou_non(): void
    {
        Notification::fake();
        $user = $this->userWithRole('agent', ['email' => 'agent@test.local']);

        $known = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'agent@test.local'])->assertOk()->json();
        $unknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'inconnu@test.local'])->assertOk()->json();

        $this->assertSame($known, $unknown);
        $this->assertNull($known['data']);
        Notification::assertSentTo($user, ResetPassword::class);
        Notification::assertCount(1);
    }

    public function test_le_lien_ouvre_la_page_de_reinitialisation_du_frontend(): void
    {
        Notification::fake();
        $user = $this->userWithRole('agent', ['email' => 'agent@test.local']);

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'agent@test.local']);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $url = $notification->toMail($user)->actionUrl;

            return str_starts_with($url, 'http://localhost:3000/reinitialisation?token=')
                && str_contains($url, 'email=agent%40test.local');
        });
    }

    public function test_aucun_lien_pour_un_compte_desactive(): void
    {
        Notification::fake();
        $this->seedRoles();
        User::factory()->inactive()->role('agent')->create(['email' => 'off@test.local']);

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'off@test.local'])->assertOk();

        Notification::assertNothingSent();
    }

    public function test_mot_de_passe_oublie_limite_429(): void
    {
        Notification::fake();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/forgot-password', ['email' => "x{$i}@test.local"])->assertOk();
        }

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'x@test.local'])
            ->assertStatus(429)
            ->assertHeader('Retry-After');
    }

    public function test_reinitialisation_valide_coupe_les_sessions_et_leve_l_obligation(): void
    {
        $this->seedRoles();
        $user = User::factory()->mustChangePassword()->role('agent')->create(['email' => 'agent@test.local']);
        DB::table('sessions')->insert(['id' => 's1', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        $token = Password::createToken($user);

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'email' => 'agent@test.local',
            'password' => 'nouveau2026mdp',
            'password_confirmation' => 'nouveau2026mdp',
        ])->assertOk()->assertJsonPath('data', null);

        $user->refresh();
        $this->assertTrue(Hash::check('nouveau2026mdp', $user->password));
        $this->assertFalse($user->must_change_password);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
    }

    public function test_lien_a_usage_unique(): void
    {
        $user = $this->userWithRole('agent', ['email' => 'agent@test.local']);
        $token = Password::createToken($user);
        $payload = ['token' => $token, 'email' => 'agent@test.local', 'password' => 'nouveau2026mdp', 'password_confirmation' => 'nouveau2026mdp'];

        $this->postJson('/api/v1/auth/reset-password', $payload)->assertOk();
        $this->postJson('/api/v1/auth/reset-password', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['token']);
    }

    public function test_lien_expire_apres_soixante_minutes(): void
    {
        $user = $this->userWithRole('agent', ['email' => 'agent@test.local']);
        $token = Password::createToken($user);

        $this->travel(61)->minutes();

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token, 'email' => 'agent@test.local', 'password' => 'nouveau2026mdp', 'password_confirmation' => 'nouveau2026mdp',
        ])->assertStatus(422)->assertJsonValidationErrors(['token']);
    }

    public function test_jeton_invalide_422(): void
    {
        $this->userWithRole('agent', ['email' => 'agent@test.local']);

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => 'faux', 'email' => 'agent@test.local', 'password' => 'nouveau2026mdp', 'password_confirmation' => 'nouveau2026mdp',
        ])->assertStatus(422)->assertJsonValidationErrors(['token']);
    }

    public function test_regles_du_mot_de_passe_a_la_reinitialisation(): void
    {
        $user = $this->userWithRole('agent', ['email' => 'agent@test.local']);
        $token = Password::createToken($user);

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token, 'email' => 'agent@test.local', 'password' => 'court1', 'password_confirmation' => 'court1',
        ])->assertStatus(422)->assertJsonValidationErrors(['password']);
    }
}
