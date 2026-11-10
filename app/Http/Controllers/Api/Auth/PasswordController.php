<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\UpdatePasswordRequest;
use App\Services\AccountService;
use App\Support\ApiResponse;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class PasswordController extends Controller
{
    public function __construct(private readonly AccountService $accounts) {}

    /**
     * PUT /auth/password (A9, A10) : ancien mot de passe exigé ; lève l'obligation
     * de changement et déconnecte les autres sessions (cahier §15).
     */
    public function update(UpdatePasswordRequest $request): JsonResponse
    {
        $user = $request->user();
        /** @var SessionGuard $guard */
        $guard = Auth::guard('web');
        $wasRemembered = $request->cookies->has($guard->getRecallerName());

        $user->forceFill([
            'password' => $request->string('password')->toString(),
            'must_change_password' => false,
            'remember_token' => Str::random(60), // invalide les cookies « Rester connecté » des autres appareils
        ])->save();

        $this->accounts->revokeSessions($user, $request->session()->getId());

        // La session courante reste ouverte (login() migre son identifiant) ;
        // son cookie de reconnexion est réémis avec le nouveau jeton s'il existait.
        $guard->login($user, $wasRemembered);

        return ApiResponse::success(null, 'Mot de passe modifié.');
    }
}
