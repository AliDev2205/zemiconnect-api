<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mot de passe provisoire (A10) : tant que must_change_password est vrai,
 * l'API refuse tout sauf le changement de mot de passe, /auth/me et la déconnexion.
 */
class EnsurePasswordChanged
{
    /** Routes accessibles avec un mot de passe provisoire. */
    public const ALLOWED_ROUTES = ['auth.me', 'auth.logout', 'auth.password.update'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->must_change_password && ! $request->routeIs(...self::ALLOWED_ROUTES)) {
            return ApiResponse::error(
                'Vous devez changer votre mot de passe provisoire avant de continuer.',
                403,
                code: 'password_change_required',
            );
        }

        return $next($request);
    }
}
