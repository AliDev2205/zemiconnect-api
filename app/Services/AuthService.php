<?php

namespace App\Services;

use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Connexion et charge utile « Me ». Chaque tentative de connexion est inscrite
 * au journal des connexions (activity_log, log_name = connexion) avec son
 * résultat : reussie, echouee ou bloquee.
 */
class AuthService
{
    public const LOG_NAME = 'connexion';

    public const RESULT_SUCCESS = 'reussie';

    public const RESULT_FAILED = 'echouee';

    public const RESULT_LOCKED = 'bloquee';

    /** Message unique : ne dit jamais lequel de l'email ou du mot de passe est faux. */
    public const INVALID_CREDENTIALS = 'Identifiants incorrects.';

    public function login(Request $request, string $email, string $password, bool $remember): User
    {
        $key = $this->throttleKey($email, $request);
        $maxAttempts = (int) config('zemiconnect.rate_limits.login.max_attempts');

        // Blocage temporaire (A4) : refusé avant toute vérification, même avec le bon mot de passe.
        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            $seconds = RateLimiter::availableIn($key);
            $this->logAttempt($request, $email, self::RESULT_LOCKED);
            $minutes = (int) ceil($seconds / 60);

            throw new ThrottleRequestsException(
                "Trop de tentatives. Réessayez dans {$minutes} minute(s).",
                headers: ['Retry-After' => $seconds],
            );
        }

        $user = User::query()->where('email', $email)->first();

        if ($user === null || ! Hash::check($password, $user->password)) {
            RateLimiter::hit($key, (int) config('zemiconnect.rate_limits.login.decay_minutes') * 60);
            $this->logAttempt($request, $email, self::RESULT_FAILED, $user);

            throw ValidationException::withMessages(['email' => [self::INVALID_CREDENTIALS]]);
        }

        // Le compte n'est signalé désactivé qu'après des identifiants corrects (pas d'énumération).
        if (! $user->isActive()) {
            $this->logAttempt($request, $email, self::RESULT_FAILED, $user, 'account_disabled');

            throw new HttpResponseException(ApiResponse::error('Ce compte est désactivé.', 403, code: 'account_disabled'));
        }

        RateLimiter::clear($key);

        Auth::guard('web')->login($user, $remember);
        $request->session()->regenerate();

        $user->forceFill(['last_login_at' => now()])->save();
        $this->logAttempt($request, $email, self::RESULT_SUCCESS, $user);

        return $user;
    }

    public function logout(Request $request): void
    {
        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
    }

    /**
     * Charge utile commune à /auth/login, /auth/me et /auth/profile.
     *
     * @return array{user: array<string, mixed>, permissions: list<string>}
     */
    public function mePayload(User $user): array
    {
        $user->loadMissing('agentProfile');

        return [
            'user' => [
                'id' => $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->email,
                'phone' => $user->phone,
                'role' => $user->getRoleNames()->first(),
                'status' => $user->status->value,
                'must_change_password' => $user->must_change_password,
                'last_login_at' => $user->last_login_at?->toIso8601ZuluString(),
                'created_at' => $user->created_at?->toIso8601ZuluString(),
                'matricule' => $user->agentProfile?->matricule,
                'organization' => $user->agentProfile?->organization,
            ],
            'permissions' => $user->getAllPermissions()->pluck('name')->sort()->values()->all(),
        ];
    }

    private function throttleKey(string $email, Request $request): string
    {
        return 'login:'.Str::lower(trim($email)).'|'.$request->ip();
    }

    private function logAttempt(Request $request, string $email, string $result, ?User $user = null, ?string $reason = null): void
    {
        activity()
            ->useLog(self::LOG_NAME)
            ->event('auth.login')
            ->causedBy($user)
            ->withProperties(array_filter([
                'email' => Str::lower(trim($email)),
                'result' => $result,
                'reason' => $reason,
                'user_agent' => Str::limit((string) $request->userAgent(), 255, ''),
            ], fn ($value) => $value !== null))
            ->log('auth.login');
    }
}
