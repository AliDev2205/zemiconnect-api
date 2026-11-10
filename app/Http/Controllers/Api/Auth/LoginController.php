<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\AuthService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class LoginController extends Controller
{
    public function __construct(private readonly AuthService $auth) {}

    /**
     * POST /auth/login : { email, password, remember } -> Me.
     * 422 identifiants incorrects (message unique), 403 account_disabled, 429 + Retry-After.
     */
    public function __invoke(LoginRequest $request): JsonResponse
    {
        $user = $this->auth->login(
            $request,
            $request->string('email')->toString(),
            $request->string('password')->toString(),
            $request->boolean('remember'),
        );

        return ApiResponse::success($this->auth->mePayload($user), 'Connexion réussie.');
    }
}
