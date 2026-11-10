<?php

namespace App\Http\Controllers\Api\Documents;

use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\StoreDocumentRequest;
use App\Models\Document;
use App\Models\Driver;
use App\Models\Motorcycle;
use App\Services\DocumentService;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Documents du conducteur et assurance de la moto (GE4 onglet Documents, GE6).
 */
class DocumentController extends Controller
{
    public function __construct(private readonly DocumentService $documents) {}

    /**
     * POST /drivers/{id}/documents : ajout ou renouvellement (l'ancien passe à is_current = false).
     */
    public function storeForDriver(StoreDocumentRequest $request, Driver $driver): JsonResponse
    {
        return $this->store($request, $driver);
    }

    /**
     * POST /motorcycles/{id}/documents : assurance de la moto.
     */
    public function storeForMotorcycle(StoreDocumentRequest $request, Motorcycle $motorcycle): JsonResponse
    {
        return $this->store($request, $motorcycle);
    }

    /**
     * GET /drivers/{id}/documents/history?type= : documents remplacés, par type.
     */
    public function historyForDriver(Request $request, Driver $driver): JsonResponse
    {
        return $this->history($request, $driver->documents(), StoreDocumentRequest::DRIVER_TYPES);
    }

    /**
     * GET /motorcycles/{id}/documents/history : assurances remplacées.
     */
    public function historyForMotorcycle(Request $request, Motorcycle $motorcycle): JsonResponse
    {
        return $this->history($request, $motorcycle->documents(), StoreDocumentRequest::MOTORCYCLE_TYPES);
    }

    private function store(StoreDocumentRequest $request, Driver|Motorcycle $owner): JsonResponse
    {
        $document = $this->documents->add($owner, $request->safe()->except('file'), $request->file('file'), $request->user());

        return ApiResponse::created($document->fresh()->toArray(), 'Document enregistré.');
    }

    /**
     * @param  HasMany<Document, *>  $relation
     * @param  list<string>  $types
     */
    private function history(Request $request, $relation, array $types): JsonResponse
    {
        $filters = $request->validate(['type' => ['nullable', Rule::in($types)]]);

        $documents = $relation
            ->where('is_current', false)
            ->when($filters['type'] ?? null, fn ($q, string $type) => $q->where('type', $type))
            ->orderBy('type')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        return ApiResponse::success($documents->map(fn (Document $document) => $document->toArray())->values());
    }
}
