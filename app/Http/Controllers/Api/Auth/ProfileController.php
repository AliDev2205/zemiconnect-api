<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\UpdateProfileRequest;
use App\Services\AuthService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class ProfileController extends Controller
{
    public function __construct(private readonly AuthService $auth) {}

    /**
     * PUT /auth/profile (A8) : nom, prénom, téléphone -> Me.
     */
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->update($request->validated());

        return ApiResponse::success($this->auth->mePayload($user), 'Profil mis à jour.');
    }
}
