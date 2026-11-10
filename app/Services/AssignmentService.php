<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Assignment;
use App\Models\Driver;
use App\Models\Motorcycle;
use App\Models\User;
use App\Support\Audit;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Affectations conducteur <-> moto (RG08, RG09). Changer de moto clôt
 * l'affectation ouverte (date, motif, auteur) et ouvre la nouvelle, dans une
 * seule transaction, puis recalcule la conformité (assurance de la nouvelle moto).
 * Le QR ne change pas.
 */
class AssignmentService
{
    public const UNAVAILABLE = 'Cette moto est déjà affectée à un autre conducteur.';

    public function __construct(private readonly ComplianceService $compliance) {}

    public function assign(Driver $driver, Motorcycle $motorcycle, User $by, ?CarbonInterface $startedAt = null, ?string $reason = null): Assignment
    {
        try {
            return DB::transaction(function () use ($driver, $motorcycle, $by, $startedAt, $reason) {
                // Verrous : deux gestionnaires ne peuvent pas affecter la même moto en même temps.
                $driver = Driver::query()->lockForUpdate()->findOrFail($driver->id);
                $motorcycle = Motorcycle::query()->lockForUpdate()->findOrFail($motorcycle->id);

                if ($driver->isArchived()) {
                    throw BusinessRuleException::driverArchived();
                }
                if ($motorcycle->isArchived()) {
                    throw BusinessRuleException::motorcycleArchived();
                }

                $taken = $motorcycle->currentAssignment()->first();
                if ($taken !== null && $taken->driver_id !== $driver->id) {
                    throw new BusinessRuleException('motorcycle_unavailable', self::UNAVAILABLE, ['motorcycle_id' => [self::UNAVAILABLE]]);
                }
                if ($taken !== null) {
                    $message = 'Cette moto est déjà affectée à ce conducteur.';
                    throw new BusinessRuleException('motorcycle_already_assigned', $message, ['motorcycle_id' => [$message]]);
                }

                $current = $driver->currentAssignment()->with('motorcycle')->first();
                $startedAt ??= now()->startOfDay();

                if ($current !== null) {
                    $this->guardChange($current, $startedAt, $reason);

                    $current->forceFill([
                        'ended_at' => $startedAt,
                        'end_reason' => $reason,
                        'ended_by' => $by->id,
                    ])->save();
                }

                $assignment = Assignment::query()->create([
                    'driver_id' => $driver->id,
                    'motorcycle_id' => $motorcycle->id,
                    'started_at' => $startedAt,
                    'start_reason' => $reason,
                    'created_by' => $by->id,
                ]);

                Audit::record(
                    'assignment.changed',
                    $assignment,
                    ['plate_number' => $current?->motorcycle?->plate_number],
                    ['plate_number' => $motorcycle->plate_number, 'started_at' => $startedAt->toDateString()],
                    $reason,
                    $driver->id,
                    $driver->fullName(),
                    $motorcycle->plate_number,
                );

                $this->compliance->recalculate($driver);

                return $assignment;
            });
        } catch (UniqueConstraintViolationException) {
            // Dernier rempart (index unique open_motorcycle_id) : course entre deux affectations.
            throw new BusinessRuleException('motorcycle_unavailable', self::UNAVAILABLE, ['motorcycle_id' => [self::UNAVAILABLE]]);
        }
    }

    /**
     * Clôt l'affectation ouverte (archivage du conducteur). À appeler dans une transaction.
     */
    public function closeCurrent(Driver $driver, User $by, string $reason): ?Assignment
    {
        $current = $driver->currentAssignment()->with('motorcycle')->lockForUpdate()->first();

        if ($current === null) {
            return null;
        }

        $today = now()->startOfDay();
        $current->forceFill([
            // Une affectation ouverte dans le futur se clôt à sa date de début (CHECK fin >= début).
            'ended_at' => $current->started_at->greaterThan($today) ? $current->started_at : $today,
            'end_reason' => $reason,
            'ended_by' => $by->id,
        ])->save();

        Audit::record(
            'assignment.closed',
            $current,
            ['plate_number' => $current->motorcycle?->plate_number],
            ['plate_number' => null],
            $reason,
            $driver->id,
            $driver->fullName(),
            $current->motorcycle?->plate_number,
        );

        return $current;
    }

    private function guardChange(Assignment $current, CarbonInterface $startedAt, ?string $reason): void
    {
        $errors = [];

        if ($reason === null || trim($reason) === '') {
            $errors['start_reason'] = ['Le motif est obligatoire pour un changement de moto.'];
        }
        if ($startedAt->toDateString() < $current->started_at->toDateString()) {
            $errors['started_at'] = ['La date ne peut pas précéder le début de l\'affectation en cours ('.$current->started_at->format('d/m/Y').').'];
        }

        if ($errors !== []) {
            throw new BusinessRuleException('assignment_change_invalid', reset($errors)[0], $errors);
        }
    }
}
