<?php

namespace App\Http\Controllers\Api\Motorcycles;

use App\Enums\MotorcycleStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Motorcycles\ArchiveMotorcycleRequest;
use App\Http\Requests\Motorcycles\StoreMotorcycleRequest;
use App\Http\Requests\Motorcycles\UpdateMotorcycleRequest;
use App\Models\Motorcycle;
use App\Services\MotorcycleService;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Motos (GE5, GE6).
 */
class MotorcycleController extends Controller
{
    public function __construct(private readonly MotorcycleService $motorcycles) {}

    /**
     * GET /motorcycles?search&unassigned&status : unassigned=true = sans conducteur et non archivées.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:50'],
            // « true » / « false » tels qu'envoyés dans une query string, en plus de 1 / 0.
            'unassigned' => ['nullable', Rule::in(['true', 'false', '1', '0'])],
            'status' => ['nullable', Rule::in(MotorcycleStatus::values())],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = Motorcycle::query()
            ->with(['currentAssignment.driver', 'currentInsurance'])
            ->when($filters['search'] ?? null, function (Builder $q, string $term) {
                $like = '%'.addcslashes(trim($term), '%_\\').'%';
                $plate = '%'.addcslashes(Motorcycle::normalizePlate($term), '%_\\').'%';
                $q->where(fn (Builder $s) => $s->where('plate_number', 'like', $plate)
                    ->orWhere('chassis_number', 'like', $like)
                    ->orWhere('brand', 'like', $like)
                    ->orWhere('model', 'like', $like));
            })
            ->when($request->boolean('unassigned'), fn (Builder $q) => $q
                ->where('status', MotorcycleStatus::Active)
                ->whereDoesntHave('currentAssignment'))
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('status', $status))
            ->orderBy('plate_number')
            ->paginate((int) ($filters['per_page'] ?? 15));

        return ApiResponse::paginated($page, fn (Motorcycle $motorcycle) => $this->present($motorcycle, withAllDocuments: false));
    }

    public function store(StoreMotorcycleRequest $request): JsonResponse
    {
        $motorcycle = $this->motorcycles->create($request->validated(), $request->user());

        return ApiResponse::created($this->present($motorcycle->fresh()), 'Moto enregistrée.');
    }

    public function show(Motorcycle $motorcycle): JsonResponse
    {
        return ApiResponse::success($this->present($motorcycle));
    }

    public function update(UpdateMotorcycleRequest $request, Motorcycle $motorcycle): JsonResponse
    {
        $motorcycle = $this->motorcycles->update($motorcycle, $request->validated());

        return ApiResponse::success($this->present($motorcycle->fresh()), 'Moto modifiée.');
    }

    /**
     * POST /motorcycles/{id}/archive : refusé tant que la moto est affectée (422 motorcycle_assigned).
     */
    public function archive(ArchiveMotorcycleRequest $request, Motorcycle $motorcycle): JsonResponse
    {
        $motorcycle = $this->motorcycles->archive($motorcycle, $request->validated('archive_reason'));

        return ApiResponse::success($this->present($motorcycle->fresh()), 'Moto archivée.');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Motorcycle $motorcycle, bool $withAllDocuments = true): array
    {
        $motorcycle->loadMissing('currentAssignment.driver');

        if ($withAllDocuments) {
            $motorcycle->load(['documents' => fn ($q) => $q->where('is_current', true)]);
        } else {
            $motorcycle->loadMissing('currentInsurance');
            $motorcycle->setRelation('documents', collect(array_filter([$motorcycle->currentInsurance])));
        }

        return array_diff_key($motorcycle->toArray(), ['current_insurance' => true]);
    }
}
