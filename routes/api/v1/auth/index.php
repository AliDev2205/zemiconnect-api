<?php

use App\Http\Controllers\Api\Auth\LoginController;
use App\Http\Controllers\Api\Auth\LogoutController;
use App\Http\Controllers\Api\Auth\MeController;
use App\Http\Controllers\Api\Auth\PasswordController;
use App\Http\Controllers\Api\Auth\PasswordResetController;
use App\Http\Controllers\Api\Auth\ProfileController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Authentification et profil
|--------------------------------------------------------------------------
| Préfixe parent : /api/v1
| Sanctum en mode SPA : le frontend appelle GET /sanctum/csrf-cookie
| (hors /api/v1) avant la connexion, puis envoie X-XSRF-TOKEN.
|
| - login : blocage après 5 échecs (email + IP) pendant 15 minutes, géré
|   dans AuthService pour journaliser les tentatives bloquées.
| - Routes connectées : compte actif exigé (EnsureAccountIsActive) ;
|   mot de passe provisoire => seules me, logout et password passent.
*/

Route::prefix('auth')->name('auth.')->group(function () {

    // ── Public ────────────────────────────────────────────────────────────
    Route::post('login', LoginController::class)->name('login');

    Route::middleware('throttle:forgot-password')->group(function () {
        Route::post('forgot-password', [PasswordResetController::class, 'forgot'])->name('password.forgot');
        Route::post('reset-password', [PasswordResetController::class, 'reset'])->name('password.reset');
    });

    // ── Connecté ──────────────────────────────────────────────────────────
    Route::middleware(['auth:sanctum', 'active', 'password.changed'])->group(function () {
        Route::post('logout', LogoutController::class)->name('logout');
        Route::get('me', MeController::class)->name('me');
        Route::put('password', [PasswordController::class, 'update'])->name('password.update');
        Route::put('profile', [ProfileController::class, 'update'])->name('profile.update');
    });
});
