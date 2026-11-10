<?php

namespace App\Http\Controllers\Api\Drivers;

use App\Enums\DriverStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Drivers\ArchiveDriverRequest;
use App\Http\Requests\Drivers\ChangeDriverStatusRequest;
use App\Models\Driver;
use App\Services\DriverStatusService;
use App\Services\DriverValidationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Valider, suspendre, réactiver, archiver (GE4). Transition interdite : 422 invalid_transition.
 */
class DriverStatusController extends Controller
{
    public function __construct(
        private readonly DriverValidationService $validation,
        private readonly DriverStatusService $statuses,
    ) {}

    /**
     * POST /drivers/{id}/validate : numéro ZemiConnect + QR actif + statut Actif.
     * 422 activation_incomplete { errors.activation: [...] } si un élément manque.
     */
    public function validateDriver(Request $request, Driver $driver): JsonResponse
    {
        $driver = $this->validation->validate($driver, $request->user());

        return ApiResponse::success(DriverController::presentDriver($driver->fresh(), $request), 'Dossier validé.');
    }

    /**
     * PATCH /drivers/{id}/status : { status: suspendu|actif, reason }.
     */
    public function update(ChangeDriverStatusRequest $request, Driver $driver): JsonResponse
    {
        $reason = $request->validated('reason');

        $driver = $request->validated('status') === DriverStatus::Suspendu->value
            ? $this->statuses->suspend($driver, (string) $reason, $request->user())
            : $this->statuses->reactivate($driver, $reason, $request->user());

        return ApiResponse::success(DriverController::presentDriver($driver, $request), 'Statut modifié.');
    }

    /**
     * POST /drivers/{id}/archive : { reason } ; QR révoqué et affectation clôturée.
     */
    public function archive(ArchiveDriverRequest $request, Driver $driver): JsonResponse
    {
        $driver = $this->statuses->archive($driver, $request->validated('reason'), $request->user());

        return ApiResponse::success(DriverController::presentDriver($driver, $request), 'Dossier archivé.');
    }
}
