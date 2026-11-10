<?php

namespace App\Http\Controllers\Api\Auth;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Models\User;
use App\Services\AccountService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PasswordResetController extends Controller
{
    public function __construct(private readonly AccountService $accounts) {}

    /**
     * POST /auth/forgot-password (A3) : même réponse que l'email existe ou non.
     * Seuls les comptes actifs reçoivent un lien.
     */
    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        Password::sendResetLink([
            'email' => $request->string('email')->toString(),
            'status' => UserStatus::Active->value,
        ]);

        return ApiResponse::success(null, 'Si un compte actif correspond à cette adresse, un lien de réinitialisation vient d\'être envoyé.');
    }

    /**
     * POST /auth/reset-password : lien à usage unique, valable 60 minutes.
     * Toutes les sessions du compte sont coupées.
     */
    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => $password,
                    'must_change_password' => false,
                    'remember_token' => Str::random(60),
                ])->save();

                $this->accounts->revokeSessions($user);
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'token' => ['Ce lien de réinitialisation est invalide ou a expiré.'],
            ]);
        }

        return ApiResponse::success(null, 'Mot de passe réinitialisé.');
    }
}
