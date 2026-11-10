<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Un compte désactivé ou archivé perd l'accès immédiatement (H4, CA20, RG17),
 * même s'il lui reste une session ou un cookie « Rester connecté ».
 */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->isActive()) {
            Auth::guard('web')->logout();

            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            return ApiResponse::error('Ce compte est désactivé.', 403, code: 'account_disabled');
        }

        return $next($request);
    }
}
