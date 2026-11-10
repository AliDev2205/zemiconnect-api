<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Services\AuthService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class LogoutController extends Controller
{
    public function __construct(private readonly AuthService $auth) {}

    /**
     * POST /auth/logout : invalide la session (A2). 204 comme le cahier (§15).
     */
    public function __invoke(Request $request): Response
    {
        $this->auth->logout($request);

        return ApiResponse::noContent();
    }
}
