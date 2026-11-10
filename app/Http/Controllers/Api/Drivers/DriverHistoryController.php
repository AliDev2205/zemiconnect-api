<?php

namespace App\Http\Controllers\Api\Drivers;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Driver;
use App\Models\ProfessionalConsultation;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;

/**
 * GET /drivers/{id}/history (GE4, onglet Historique) : fil chronologique,
 * plus récent d'abord. Audit (création, modifications avant / après, statuts
 * et motifs, documents, affectations, QR) + consultations des agents.
 */
class DriverHistoryController extends Controller
{
    private const RESOURCE_TYPES = [
        'Driver' => 'driver',
        'Motorcycle' => 'motorcycle',
        'Document' => 'document',
        'Assignment' => 'assignment',
        'QrCode' => 'qr_code',
    ];

    public function __invoke(Driver $driver): JsonResponse
    {
        $audit = Activity::query()
            ->with('causer')
            ->where('log_name', Audit::LOG_NAME)
            ->where(fn ($q) => $q
                ->where(fn ($s) => $s->where('subject_type', Driver::class)->where('subject_id', $driver->id))
                ->orWhere('properties->driver_id', $driver->id))
            ->orderByDesc('id')
            ->limit(500)
            ->get()
            ->map(fn (Activity $activity) => $this->auditEntry($activity, $driver));

        $consultations = ProfessionalConsultation::query()
            ->with('agent')
            ->where('driver_id', $driver->id)
            ->orderByDesc('id')
            ->limit(500)
            ->get()
            ->map(fn (ProfessionalConsultation $consultation) => [
                'id' => 'consultation-'.$consultation->id,
                'kind' => 'consultation',
                'action' => 'consultation.'.$consultation->channel->value,
                'actor_name' => $consultation->agent?->fullName(),
                'actor_role' => 'agent',
                'resource_type' => 'driver',
                'resource_id' => $driver->id,
                'resource_label' => $driver->fullName(),
                'driver_id' => $driver->id,
                'detail' => null,
                'old_values' => null,
                'new_values' => null,
                'reason' => null,
                'ip_address' => $consultation->ip_address,
                'created_at' => $consultation->created_at?->toIso8601ZuluString(),
            ]);

        $entries = $audit->concat($consultations)
            ->sortByDesc('created_at')
            ->values();

        return ApiResponse::success($entries);
    }

    /**
     * @return array<string, mixed>
     */
    private function auditEntry(Activity $activity, Driver $driver): array
    {
        $properties = $activity->properties;
        /** @var User|null $causer */
        $causer = $activity->causer;

        return [
            'id' => 'audit-'.$activity->id,
            'kind' => 'audit',
            'action' => $activity->event ?? $activity->description,
            'actor_name' => $causer?->fullName(),
            'actor_role' => $causer?->getRoleNames()->first(),
            'resource_type' => self::RESOURCE_TYPES[class_basename((string) $activity->subject_type)] ?? null,
            'resource_id' => $activity->subject_id,
            'resource_label' => $properties->get('label'),
            'driver_id' => $driver->id,
            'detail' => $properties->get('detail'),
            'old_values' => $properties->get('old') ?: null,
            'new_values' => $properties->get('attributes') ?: null,
            'reason' => $properties->get('reason'),
            'ip_address' => $activity->ip_address,
            'created_at' => $activity->created_at?->toIso8601ZuluString(),
        ];
    }
}
