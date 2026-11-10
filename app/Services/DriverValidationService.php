<?php

namespace App\Services;

use App\Enums\DocumentType;
use App\Enums\DriverStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Driver;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Validation d'un dossier En attente (RG02, RG04, RG15), en une seule transaction :
 * contrôle des éléments d'activation, attribution du numéro ZemiConnect,
 * création du QR actif, passage à Actif. La conformité n'est pas exigée.
 */
class DriverValidationService
{
    /** Éléments d'activation (RG04), dans l'ordre de la liste de contrôle GE3. */
    public const REQUIREMENTS = ['photo', 'id_card', 'driving_license', 'motorcycle'];

    private const MAX_NUMBER_ATTEMPTS = 3;

    public function __construct(
        private readonly ZemiNumberGenerator $numbers,
        private readonly QrCodeService $qrCodes,
        private readonly ComplianceService $compliance,
    ) {}

    /**
     * Éléments manquants pour l'activation (liste vide = dossier complet).
     *
     * @return list<string>
     */
    public function missing(Driver $driver): array
    {
        $types = $driver->currentDocuments()->pluck('type')->map(fn ($type) => $type instanceof DocumentType ? $type->value : $type)->all();

        return array_values(array_filter([
            $driver->photo_path === null ? 'photo' : null,
            in_array(DocumentType::IdCard->value, $types, true) ? null : 'id_card',
            in_array(DocumentType::DrivingLicense->value, $types, true) ? null : 'driving_license',
            $driver->currentAssignment()->exists() ? null : 'motorcycle',
        ]));
    }

    public function validate(Driver $driver, User $by): Driver
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(fn () => $this->validateOnce($driver, $by));
            } catch (UniqueConstraintViolationException $e) {
                // Deux validations simultanées ont tiré le même numéro : on rejoue la transaction.
                if ($attempt >= self::MAX_NUMBER_ATTEMPTS || ! str_contains($e->getMessage(), 'drivers_zemi_number_unique')) {
                    throw $e;
                }
            }
        }
    }

    private function validateOnce(Driver $driver, User $by): Driver
    {
        $driver = Driver::query()->lockForUpdate()->findOrFail($driver->id);

        if ($driver->status !== DriverStatus::EnAttente) {
            throw BusinessRuleException::invalidTransition('Seul un dossier En attente peut être validé.');
        }

        $missing = $this->missing($driver);
        if ($missing !== []) {
            throw new BusinessRuleException('activation_incomplete', 'Le dossier est incomplet : il ne peut pas être validé.', ['activation' => $missing]);
        }

        $driver->forceFill([
            'zemi_number' => $this->numbers->generate(),
            'status' => DriverStatus::Actif,
            'validated_at' => now(),
            'validated_by' => $by->id,
            'updated_by' => $by->id,
        ])->save();

        Audit::record(
            'driver.validated',
            $driver,
            ['status' => DriverStatus::EnAttente, 'zemi_number' => null],
            ['status' => DriverStatus::Actif, 'zemi_number' => $driver->zemi_number],
            driverId: $driver->id,
            label: $driver->fullName(),
        );

        $this->qrCodes->createActive($driver, $by);
        $this->compliance->recalculate($driver);

        return $driver;
    }
}
