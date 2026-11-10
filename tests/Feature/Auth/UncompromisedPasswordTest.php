<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * §17 : mots de passe compromis refusés côté API (service Have I Been Pwned,
 * k-anonymat : seul le préfixe du hachage SHA-1 est envoyé). Si le service
 * ne répond pas, la saisie n'est pas bloquée.
 */
class UncompromisedPasswordTest extends TestCase
{
    use RefreshDatabase;

    private const LEAKED = 'azerty12345';

    protected function fakePwnedPasswords(): void
    {
        // Chaque test pose sa propre réponse du service.
    }

    private function change(string $password)
    {
        return $this->actingAs($this->userWithRole('gestionnaire'))->putJson('/api/v1/auth/password', [
            'current_password' => 'motdepasse2026',
            'password' => $password,
            'password_confirmation' => $password,
        ]);
    }

    public function test_mot_de_passe_compromis_refuse(): void
    {
        $hash = strtoupper(sha1(self::LEAKED));
        Http::fake(['api.pwnedpasswords.com/range/'.substr($hash, 0, 5) => Http::response(substr($hash, 5).":4120\r\nAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA:1")]);

        $this->change(self::LEAKED)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/range/'.substr($hash, 0, 5)));
    }

    public function test_mot_de_passe_inconnu_du_service_accepte(): void
    {
        Http::fake(['api.pwnedpasswords.com/*' => Http::response("AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA:1\r\n")]);

        $this->change('Zemi2026Parakou')->assertOk();
    }

    /**
     * Service en panne : la saisie n'est pas bloquée. Simulé par une réponse 503 :
     * sur ce poste (PHP 8.3.0 ZTS Windows), simuler un échec de connexion
     * (Http::failedConnection) fait planter PHP lui-même. En réel, NotPwnedVerifier
     * attrape l'exception de connexion et laisse aussi passer le mot de passe.
     */
    public function test_service_en_panne_la_saisie_n_est_pas_bloquee(): void
    {
        Http::fake(['api.pwnedpasswords.com/*' => Http::response('Service Unavailable', 503)]);

        $response = $this->change(self::LEAKED);

        $response->assertOk();
        $this->assertTrue(Hash::check(self::LEAKED, auth()->user()->fresh()->password));
    }

    public function test_delai_d_attente_court(): void
    {
        $this->assertSame(3, config('zemiconnect.uncompromised_timeout'));
    }
}
