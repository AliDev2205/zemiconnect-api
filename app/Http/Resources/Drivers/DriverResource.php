<?php

namespace App\Http\Resources\Drivers;

use App\Models\Driver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

/**
 * Dossier conducteur, données administratives (gestionnaire, administrateur).
 * Noms de champs et valeurs de la base. Aucun chemin de fichier : la photo
 * passe par une URL signée de courte durée.
 *
 * Relations attendues (chargées par le contrôleur) : currentAssignment.motorcycle
 * (+ currentInsurance sur la fiche) et, sur la fiche, documents (actuels).
 *
 * @mixin Driver
 */
class DriverResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $assignment = $this->relationLoaded('currentAssignment') ? $this->currentAssignment : null;
        $motorcycle = $assignment?->motorcycle;

        return [
            'id' => $this->id,
            'zemi_number' => $this->zemi_number,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'phone' => $this->phone,
            'address' => $this->address,
            'birth_date' => $this->birth_date?->toDateString(),
            'identification_number' => $this->identification_number,
            'photo_url' => $this->photo_path === null ? null : URL::temporarySignedRoute(
                'drivers.photo',
                now()->addMinutes((int) config('zemiconnect.files.signed_url_minutes')),
                ['driver' => $this->id, 'v' => substr(md5($this->photo_path), 0, 8)],
            ),
            'status' => $this->status->value,
            'status_reason' => $this->status_reason,
            'compliance_status' => $this->compliance_status->value,
            'compliance_checked_at' => $this->compliance_checked_at?->toIso8601ZuluString(),
            'motorcycle' => $motorcycle === null ? null : [
                'id' => $motorcycle->id,
                'plate_number' => $motorcycle->plate_number,
                'brand' => $motorcycle->brand,
                'model' => $motorcycle->model,
                'color' => $motorcycle->color,
                'manufacture_year' => $motorcycle->manufacture_year,
                'chassis_number' => $motorcycle->chassis_number,
                'status' => $motorcycle->status->value,
                'documents' => $motorcycle->relationLoaded('currentInsurance') && $motorcycle->currentInsurance
                    ? [$motorcycle->currentInsurance->toArray()]
                    : [],
            ],
            'assignment_started_at' => $assignment?->started_at?->toDateString(),
            'documents' => $this->whenLoaded('documents', fn () => $this->documents->values()->toArray()),
            'validated_at' => $this->validated_at?->toIso8601ZuluString(),
            'archived_at' => $this->archived_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }
}
