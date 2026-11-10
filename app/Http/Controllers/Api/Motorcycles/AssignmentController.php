<?php

namespace App\Http\Controllers\Api\Motorcycles;

use App\Http\Controllers\Api\Drivers\DriverController;
use App\Http\Controllers\Controller;
use App\Http\Requests\Drivers\AssignMotorcycleRequest;
use App\Models\Assignment;
use App\Models\Driver;
use App\Models\Motorcycle;
use App\Services\AssignmentService;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

/**
 * Affectations (GE4 onglet Moto, GE6) : changement de moto et historiques.
 */
class AssignmentController extends Controller
{
    public function __construct(private readonly AssignmentService $assignments) {}

    /**
     * POST /drivers/{id}/assignment : { motorcycle_id, started_at?, start_reason? }.
     * 422 sur motorcycle_id si la moto est déjà affectée à un autre conducteur.
     */
    public function store(AssignMotorcycleRequest $request, Driver $driver): JsonResponse
    {
        $startedAt = $request->validated('started_at');

        $this->assignments->assign(
            $driver,
            Motorcycle::query()->findOrFail($request->validated('motorcycle_id')),
            $request->user(),
            $startedAt ? Carbon::createFromFormat('Y-m-d', $startedAt)->startOfDay() : null,
            $request->validated('start_reason'),
        );

        return ApiResponse::success(DriverController::presentDriver($driver->fresh(), $request), 'Moto affectée.');
    }

    /**
     * GET /drivers/{id}/assignments : plus récente d'abord.
     */
    public function forDriver(Driver $driver): JsonResponse
    {
        return ApiResponse::success($this->history($driver->assignments()));
    }

    /**
     * GET /motorcycles/{id}/assignments : conducteurs successifs de la moto.
     */
    public function forMotorcycle(Motorcycle $motorcycle): JsonResponse
    {
        return ApiResponse::success($this->history($motorcycle->assignments()));
    }

    /**
     * @param  HasMany<Assignment, *>  $relation
     * @return list<array<string, mixed>>
     */
    private function history($relation): array
    {
        return $relation->with(['driver', 'motorcycle'])
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Assignment $assignment) => $assignment->toArray())
            ->all();
    }
}
