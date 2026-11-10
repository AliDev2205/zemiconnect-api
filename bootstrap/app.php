<?php

use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sanctum en mode SPA : session par cookie pour les requêtes du frontend (SANCTUM_STATEFUL_DOMAINS).
        $middleware->statefulApi();

        $middleware->alias([
            'active' => EnsureAccountIsActive::class,
            'password.changed' => EnsurePasswordChanged::class,
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
        ]);

        // API sans page de connexion côté serveur : jamais de redirection, un 401 JSON.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Toutes les erreurs de l'API au format { status: "error", message, errors?, code? }.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return match (true) {
                // Réponse déjà construite (ex. 403 account_disabled) : renvoyée telle quelle, debug ou non.
                $e instanceof HttpResponseException => $e->getResponse(),
                $e instanceof ValidationException => ApiResponse::error($e->getMessage(), 422, $e->errors()),
                $e instanceof AuthenticationException => ApiResponse::error('Non authentifié.', 401),
                $e instanceof AuthorizationException, $e instanceof UnauthorizedException, $e instanceof AccessDeniedHttpException => ApiResponse::error('Accès refusé.', 403),
                $e instanceof ModelNotFoundException, $e instanceof NotFoundHttpException => ApiResponse::error('Ressource introuvable.', 404),
                $e instanceof ThrottleRequestsException => ApiResponse::error(
                    $e->getMessage() !== '' && $e->getMessage() !== 'Too Many Attempts.' ? $e->getMessage() : 'Trop de tentatives. Réessayez plus tard.',
                    429,
                    headers: $e->getHeaders(),
                ),
                $e instanceof TokenMismatchException => ApiResponse::error('Session expirée, veuillez réessayer.', 419),
                $e instanceof HttpExceptionInterface => ApiResponse::error(
                    $e->getMessage() !== '' ? $e->getMessage() : 'Erreur.',
                    $e->getStatusCode(),
                    headers: $e->getHeaders(),
                ),
                config('app.debug') => null, // détail de l'erreur en développement
                default => ApiResponse::error('Erreur interne du serveur.', 500),
            };
        });
    })->create();
