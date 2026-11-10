<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Services\AuthService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function __construct(private readonly AuthService $auth) {}

    /**
     * GET /auth/me : utilisateur connecté, rôle et permissions.
     */
    public function __invoke(Request $request): JsonResponse
    {
        return ApiResponse::success($this->auth->mePayload($request->user()));
    }
}
