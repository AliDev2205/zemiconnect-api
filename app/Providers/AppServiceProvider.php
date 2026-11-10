<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\NotPwnedVerifier;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Mots de passe compromis (§17) : délai court ; en cas d'échec du service,
        // NotPwnedVerifier laisse passer le mot de passe (la saisie n'est jamais bloquée).
        $this->app->singleton(UncompromisedVerifier::class, fn ($app) => new NotPwnedVerifier(
            $app->make(HttpFactory::class),
            (int) config('zemiconnect.uncompromised_timeout', 3),
        ));
    }

    public function boot(): void
    {
        // Règle du mot de passe : celle du frontend (10 caractères, une lettre, un chiffre)
        // + refus des mots de passe compromis, côté API seulement.
        Password::defaults(fn () => Password::min(10)->letters()->numbers()->uncompromised());

        // Le lien de réinitialisation ouvre la page du frontend (AU3).
        ResetPassword::createUrlUsing(function (User $user, string $token): string {
            return rtrim((string) config('zemiconnect.frontend_url'), '/')
                .'/reinitialisation?token='.$token.'&email='.urlencode($user->email);
        });

        // Identifiants internes numériques uniquement (évite toute confusion avec /drivers/duplicates…).
        Route::pattern('driver', '[0-9]+');
        Route::pattern('motorcycle', '[0-9]+');
        Route::pattern('document', '[0-9]+');

        $this->configureRateLimiting();
    }

    /**
     * Limites de débit (§17). La connexion a son propre compteur email + IP
     * dans AuthService, pour pouvoir journaliser les tentatives bloquées.
     */
    private function configureRateLimiting(): void
    {
        $limits = config('zemiconnect.rate_limits');

        RateLimiter::for('forgot-password', fn (Request $request) => Limit::perMinute($limits['forgot_password']['per_minute'])->by($request->ip()));

        // Profil public par jeton et recherche publique par numéro (routes des lots suivants).
        RateLimiter::for('public', fn (Request $request) => [
            Limit::perMinute($limits['public']['per_minute'])->by('public-minute:'.$request->ip()),
            Limit::perDay($limits['public']['per_day'])->by('public-day:'.$request->ip()),
        ]);
    }
}
