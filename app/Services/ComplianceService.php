<?php

namespace App\Services;

use App\Enums\ComplianceStatus;
use App\Enums\DocumentType;
use App\Enums\DocumentValidity;
use App\Models\Document;
use App\Models\Driver;
use Carbon\CarbonInterface;

/**
 * Conformité documentaire (RG05, RG06, RG07), valeur calculée et mise en cache
 * dans drivers.compliance_status. Documents lus : permis et droit de taxi
 * actuels du conducteur, assurance actuelle de la moto de son affectation
 * ouverte. La pièce d'identité et la fiche papier ne comptent pas.
 *
 * - un document manquant ou expiré -> non_conforme ;
 * - sinon, une expiration dans les 30 jours -> expire_bientot ;
 * - sinon -> conforme. Un document sans date d'expiration est valide (B3).
 *
 * Le calcul ne modifie jamais le statut du dossier (RG05).
 */
class ComplianceService
{
    /**
     * Documents pris en compte, par type (null = manquant).
     *
     * @return array{driving_license: ?Document, taxi_license: ?Document, insurance: ?Document}
     */
    public function relevantDocuments(Driver $driver): array
    {
        $current = $driver->currentDocuments()
            ->whereIn('type', [DocumentType::DrivingLicense, DocumentType::TaxiLicense])
            ->get()
            ->keyBy(fn (Document $document) => $document->type->value);

        $motorcycle = $driver->currentAssignment()->with('motorcycle.currentInsurance')->first()?->motorcycle;

        return [
            'driving_license' => $current->get(DocumentType::DrivingLicense->value),
            'taxi_license' => $current->get(DocumentType::TaxiLicense->value),
            'insurance' => $motorcycle?->currentInsurance,
        ];
    }

    public function compute(Driver $driver, ?CarbonInterface $today = null): ComplianceStatus
    {
        $status = ComplianceStatus::Conforme;

        foreach ($this->relevantDocuments($driver) as $document) {
            $validity = $document === null
                ? DocumentValidity::Manquant
                : DocumentValidity::fromExpiry($document->expires_at, $today);

            if ($validity === DocumentValidity::Manquant || $validity === DocumentValidity::Expire) {
                return ComplianceStatus::NonConforme;
            }

            if ($validity === DocumentValidity::ExpireBientot) {
                $status = ComplianceStatus::ExpireBientot;
            }
        }

        return $status;
    }

    /**
     * Recalcule et enregistre la conformité (sans toucher au statut ni à updated_at).
     */
    public function recalculate(Driver $driver, ?CarbonInterface $today = null): ComplianceStatus
    {
        $compliance = $this->compute($driver, $today);

        $driver->forceFill([
            'compliance_status' => $compliance,
            'compliance_checked_at' => now(),
        ]);
        $driver->timestamps = false;
        $driver->save();
        $driver->timestamps = true;

        return $compliance;
    }

    /**
     * Recalcul de tous les dossiers (tâche nocturne : passage du temps).
     *
     * @return array{checked: int, changed: int}
     */
    public function recalculateAll(?CarbonInterface $today = null): array
    {
        $checked = 0;
        $changed = 0;

        Driver::query()->orderBy('id')->chunkById(200, function ($drivers) use (&$checked, &$changed, $today) {
            foreach ($drivers as $driver) {
                $before = $driver->compliance_status;
                $after = $this->recalculate($driver, $today);
                $checked++;
                $changed += $before === $after ? 0 : 1;
            }
        });

        return ['checked' => $checked, 'changed' => $changed];
    }
}
