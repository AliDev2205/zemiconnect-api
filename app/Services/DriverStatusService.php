<?php

namespace App\Services;

use App\Enums\DriverStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Driver;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Transitions de statut (GE4) :
 * - Actif -> Suspendu (motif obligatoire) ;
 * - Suspendu -> Actif (réactivation) ;
 * - Actif ou Suspendu -> Archivé (motif obligatoire ; QR révoqué et affectation
 *   clôturée dans la même transaction, RG13).
 * Toute autre transition : 422 invalid_transition.
 *
 * Écart signalé : GE4 permet d'archiver un dossier En attente, mais le CHECK
 * C13 de la base (« En attente <=> sans numéro ») l'interdit : un dossier
 * archivé sans numéro serait refusé. L'archivage n'est donc proposé qu'aux
 * dossiers validés.
 */
class DriverStatusService
{
    public function __construct(
        private readonly QrCodeService $qrCodes,
        private readonly AssignmentService $assignments,
        private readonly ComplianceService $compliance,
    ) {}

    public function suspend(Driver $driver, string $reason, User $by): Driver
    {
        return $this->transition($driver, [DriverStatus::Actif], DriverStatus::Suspendu, 'driver.suspended', $reason, $by,
            'Seul un dossier Actif peut être suspendu.');
    }

    public function reactivate(Driver $driver, ?string $reason, User $by): Driver
    {
        return $this->transition($driver, [DriverStatus::Suspendu], DriverStatus::Actif, 'driver.reactivated', $reason, $by,
            'Seul un dossier Suspendu peut être réactivé.');
    }

    public function archive(Driver $driver, string $reason, User $by): Driver
    {
        return $this->transition($driver, [DriverStatus::Actif, DriverStatus::Suspendu], DriverStatus::Archive, 'driver.archived', $reason, $by,
            'Seul un dossier Actif ou Suspendu peut être archivé.',
            function (Driver $driver) use ($reason, $by) {
                $this->qrCodes->revokeActive($driver, $by, 'Dossier archivé : '.$reason);
                $this->assignments->closeCurrent($driver, $by, 'Dossier archivé : '.$reason);
            });
    }

    /**
     * @param  list<DriverStatus>  $from
     */
    private function transition(Driver $driver, array $from, DriverStatus $to, string $action, ?string $reason, User $by, string $refusal, ?callable $after = null): Driver
    {
        return DB::transaction(function () use ($driver, $from, $to, $action, $reason, $by, $refusal, $after) {
            $driver = Driver::query()->lockForUpdate()->findOrFail($driver->id);

            if ($driver->isArchived()) {
                throw BusinessRuleException::invalidTransition('Ce dossier est archivé : aucune transition n\'est possible.');
            }
            if (! in_array($driver->status, $from, true)) {
                throw BusinessRuleException::invalidTransition($refusal);
            }

            $old = ['status' => $driver->status, 'status_reason' => $driver->status_reason];

            $driver->forceFill([
                'status' => $to,
                // Motif de la dernière suspension ou de l'archivage ; effacé à la réactivation.
                'status_reason' => $to === DriverStatus::Actif ? null : $reason,
                'archived_at' => $to === DriverStatus::Archive ? now() : $driver->archived_at,
                'updated_by' => $by->id,
            ])->save();

            Audit::record($action, $driver, $old, ['status' => $to, 'status_reason' => $driver->status_reason], $reason, $driver->id, $driver->fullName());

            if ($after !== null) {
                $after($driver);
                $this->compliance->recalculate($driver);
            }

            return $driver->refresh();
        });
    }
}
