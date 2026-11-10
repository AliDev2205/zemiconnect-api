<?php

namespace App\Support;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Format de réponse commun (repris de HealthBook) :
 *   succès  { status: "success", message, data }
 *   erreur  { status: "error", message, errors?, code? }
 *   liste   data = { data: [], total, per_page, current_page, last_page }
 *
 * Méthodes statiques (et non un trait comme dans HealthBook) pour être
 * utilisables aussi depuis le rendu des exceptions (bootstrap/app.php).
 */
final class ApiResponse
{
    public static function success(mixed $data = null, string $message = 'OK', int $status = 200): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'message' => $message,
            'data' => $data,
        ], $status);
    }

    public static function created(mixed $data = null, string $message = 'Ressource créée.'): JsonResponse
    {
        return self::success($data, $message, 201);
    }

    public static function noContent(): Response
    {
        return response()->noContent();
    }

    /**
     * @param  array<string, list<string>>|null  $errors
     * @param  array<string, string>  $headers
     */
    public static function error(string $message, int $status, ?array $errors = null, ?string $code = null, array $headers = []): JsonResponse
    {
        $body = ['status' => 'error', 'message' => $message];

        if ($errors !== null) {
            $body['errors'] = $errors;
        }

        if ($code !== null) {
            $body['code'] = $code;
        }

        return response()->json($body, $status, $headers);
    }

    /**
     * @param  callable|null  $map  transformation de chaque élément
     */
    public static function paginated(LengthAwarePaginator $paginator, ?callable $map = null, string $message = 'OK'): JsonResponse
    {
        $items = collect($paginator->items());

        return self::success([
            'data' => $map ? $items->map($map)->values() : $items->values(),
            'total' => $paginator->total(),
            'per_page' => $paginator->perPage(),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
        ], $message);
    }
}
