<?php

namespace App\Services;

use App\Enums\MotorcycleStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Motorcycle;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Motos (RG10). L'immatriculation arrive déjà normalisée (Form Request).
 * Archivage refusé tant que la moto est affectée.
 */
class MotorcycleService
{
    public const FIELDS = ['plate_number', 'chassis_number', 'brand', 'model', 'color', 'manufacture_year'];

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $by): Motorcycle
    {
        return DB::transaction(function () use ($data, $by) {
            $motorcycle = Motorcycle::query()->create([
                ...array_intersect_key($data, array_flip(self::FIELDS)),
                'status' => MotorcycleStatus::Active,
                'created_by' => $by->id,
            ]);

            Audit::record('motorcycle.created', $motorcycle, new: array_intersect_key($motorcycle->getAttributes(), array_flip(self::FIELDS)), label: $motorcycle->plate_number);

            return $motorcycle;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Motorcycle $motorcycle, array $data): Motorcycle
    {
        return DB::transaction(function () use ($motorcycle, $data) {
            $motorcycle = Motorcycle::query()->lockForUpdate()->findOrFail($motorcycle->id);

            if ($motorcycle->isArchived()) {
                throw new BusinessRuleException('motorcycle_archived', 'Cette moto est archivée : elle ne peut plus être modifiée.');
            }

            $motorcycle->fill(array_intersect_key($data, array_flip(self::FIELDS)));

            if ($motorcycle->isDirty()) {
                [$old, $new] = Audit::diff($motorcycle);
                $motorcycle->save();
                Audit::record('motorcycle.updated', $motorcycle, $old, $new, driverId: $motorcycle->currentAssignment()->value('driver_id'), label: $motorcycle->plate_number);
            }

            return $motorcycle;
        });
    }

    public function archive(Motorcycle $motorcycle, string $reason): Motorcycle
    {
        return DB::transaction(function () use ($motorcycle, $reason) {
            $motorcycle = Motorcycle::query()->lockForUpdate()->findOrFail($motorcycle->id);

            if ($motorcycle->isArchived()) {
                throw new BusinessRuleException('motorcycle_archived', 'Cette moto est déjà archivée.');
            }
            if ($motorcycle->currentAssignment()->exists()) {
                throw new BusinessRuleException('motorcycle_assigned', 'Cette moto est affectée à un conducteur : changez d\'abord la moto du conducteur.');
            }

            $motorcycle->forceFill([
                'status' => MotorcycleStatus::Archived,
                'archived_at' => now(),
                'archive_reason' => $reason,
            ])->save();

            Audit::record('motorcycle.archived', $motorcycle, ['status' => MotorcycleStatus::Active], ['status' => MotorcycleStatus::Archived], $reason, label: $motorcycle->plate_number);

            return $motorcycle;
        });
    }
}
