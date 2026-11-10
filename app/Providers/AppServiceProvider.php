<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Règle du mot de passe, identique au frontend : 10 caractères, une lettre, un chiffre.
        Password::defaults(fn () => Password::min(10)->letters()->numbers());

        // Le lien de réinitialisation ouvre la page du frontend (AU3).
        ResetPassword::createUrlUsing(function (User $user, string $token): string {
            return rtrim((string) config('zemiconnect.frontend_url'), '/')
                .'/reinitialisation?token='.$token.'&email='.urlencode($user->email);
        });

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
