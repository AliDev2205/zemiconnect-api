<?php

namespace App\Http\Controllers\Api\Drivers;

use App\Enums\ComplianceStatus;
use App\Enums\DriverStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Drivers\StoreDriverRequest;
use App\Http\Requests\Drivers\UpdateDriverPhotoRequest;
use App\Http\Requests\Drivers\UpdateDriverRequest;
use App\Http\Resources\Drivers\DriverResource;
use App\Models\Driver;
use App\Models\Motorcycle;
use App\Services\DriverService;
use App\Services\ZemiNumberGenerator;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Dossiers conducteurs (GE2, GE3, GE4 onglet Identité).
 */
class DriverController extends Controller
{
    public function __construct(private readonly DriverService $drivers) {}

    /**
     * GET /drivers : recherche unique (nom, prénom, numéro, téléphone, identifiant,
     * immatriculation actuelle), filtres statut / conformité / période, tri par date d'enregistrement.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(DriverStatus::values())],
            'compliance_status' => ['nullable', Rule::in(ComplianceStatus::values())],
            'registered_from' => ['nullable', 'date_format:Y-m-d'],
            'registered_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:registered_from'],
            'sort' => ['nullable', Rule::in(['created_at', '-created_at'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Driver::query()
            ->with('currentAssignment.motorcycle')
            ->when($filters['search'] ?? null, fn (Builder $q, string $term) => $this->search($q, $term))
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($filters['compliance_status'] ?? null, fn (Builder $q, string $c) => $q->where('compliance_status', $c))
            ->when($filters['registered_from'] ?? null, fn (Builder $q, string $from) => $q->where('created_at', '>=', $from.' 00:00:00'))
            ->when($filters['registered_to'] ?? null, fn (Builder $q, string $to) => $q->where('created_at', '<=', $to.' 23:59:59'))
            ->orderBy('created_at', ($filters['sort'] ?? '-created_at') === 'created_at' ? 'asc' : 'desc')
            ->orderBy('id', ($filters['sort'] ?? '-created_at') === 'created_at' ? 'asc' : 'desc');

        $page = $query->paginate((int) ($filters['per_page'] ?? 15));

        return ApiResponse::paginated($page, fn (Driver $driver) => (new DriverResource($driver))->resolve($request));
    }

    /**
     * GET /drivers/duplicates : avertissement non bloquant (GE3 étape 1) —
     * même téléphone, même identifiant, ou mêmes nom et prénom.
     */
    public function duplicates(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['nullable', 'string', 'max:20'],
            'identification_number' => ['nullable', 'string', 'max:50'],
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'exclude' => ['nullable', 'integer'],
        ]);

        $phone = preg_replace('/\D+/', '', (string) ($data['phone'] ?? ''));
        $identifier = trim((string) ($data['identification_number'] ?? ''));
        $first = trim((string) ($data['first_name'] ?? ''));
        $last = trim((string) ($data['last_name'] ?? ''));

        if ($phone === '' && $identifier === '' && ($first === '' || $last === '')) {
            return ApiResponse::success([]);
        }

        $candidates = Driver::query()
            ->when($data['exclude'] ?? null, fn (Builder $q, int $id) => $q->whereKeyNot($id))
            ->where(function (Builder $q) use ($phone, $identifier, $first, $last) {
                if ($phone !== '') {
                    // Comparaison sur les seuls chiffres (espaces, tirets, points ignorés).
                    $q->orWhereRaw("REGEXP_REPLACE(phone, '[^0-9]', '') = ?", [$phone]);
                }
                if ($identifier !== '') {
                    $q->orWhere('identification_number', $identifier);
                }
                if ($first !== '' && $last !== '') {
                    $q->orWhere(fn (Builder $n) => $n->where('first_name', $first)->where('last_name', $last));
                }
            })
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        return ApiResponse::success($candidates->map(fn (Driver $driver) => [
            'id' => $driver->id,
            'name' => $driver->fullName(),
            'zemi_number' => $driver->zemi_number,
            'status' => $driver->status->value,
            'reasons' => array_values(array_filter([
                $phone !== '' && preg_replace('/\D+/', '', $driver->phone) === $phone ? 'phone' : null,
                $identifier !== '' && mb_strtolower((string) $driver->identification_number) === mb_strtolower($identifier) ? 'identifier' : null,
                $first !== '' && $last !== '' && mb_strtolower($driver->first_name) === mb_strtolower($first) && mb_strtolower($driver->last_name) === mb_strtolower($last) ? 'name' : null,
            ])),
        ])->values());
    }

    /**
     * POST /drivers : dossier créé En attente (étape Identité), fiche papier facultative.
     */
    public function store(StoreDriverRequest $request): JsonResponse
    {
        $driver = $this->drivers->create($request->safe()->except('registration_form'), $request->file('registration_form'), $request->user());

        return ApiResponse::created($this->present($driver, $request), 'Dossier enregistré en attente.');
    }

    public function show(Request $request, Driver $driver): JsonResponse
    {
        return ApiResponse::success($this->present($driver, $request));
    }

    /**
     * PUT /drivers/{id} (ou POST ?_method=PUT en multipart).
     */
    public function update(UpdateDriverRequest $request, Driver $driver): JsonResponse
    {
        $driver = $this->drivers->update($driver, $request->safe()->except('registration_form'), $request->file('registration_form'), $request->user());

        return ApiResponse::success($this->present($driver, $request), 'Dossier modifié.');
    }

    /**
     * POST /drivers/{id}/photo : upload ou remplacement.
     */
    public function updatePhoto(UpdateDriverPhotoRequest $request, Driver $driver): JsonResponse
    {
        $driver = $this->drivers->updatePhoto($driver, $request->file('photo'), $request->user());

        return ApiResponse::success($this->present($driver, $request), 'Photo enregistrée.');
    }

    /**
     * GET /drivers/{id}/photo?signature=… : URL signée de courte durée (photo_url), sans session.
     */
    public function photo(Driver $driver): StreamedResponse
    {
        $disk = Storage::disk(config('zemiconnect.files.disk'));
        abort_if($driver->photo_path === null || ! $disk->exists($driver->photo_path), 404);

        return $disk->response($driver->photo_path, 'photo.jpg', [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Fiche complète : moto actuelle et son assurance, documents actuels.
     *
     * @return array<string, mixed>
     */
    public static function presentDriver(Driver $driver, Request $request): array
    {
        $driver->load([
            'currentAssignment.motorcycle.currentInsurance',
            'documents' => fn ($q) => $q->where('is_current', true)->orderBy('type'),
        ]);

        return (new DriverResource($driver))->resolve($request);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Driver $driver, Request $request): array
    {
        return self::presentDriver($driver->fresh(), $request);
    }

    private function search(Builder $query, string $term): void
    {
        $term = trim($term);
        $like = '%'.addcslashes($term, '%_\\').'%';
        $numbers = app(ZemiNumberGenerator::class);
        $plate = '%'.addcslashes(Motorcycle::normalizePlate($term), '%_\\').'%';
        $digits = preg_replace('/\D+/', '', $term);

        $query->where(function (Builder $q) use ($term, $like, $numbers, $plate, $digits) {
            $q->where('last_name', 'like', $like)
                ->orWhere('first_name', 'like', $like)
                ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", [$like])
                ->orWhereRaw("CONCAT(last_name, ' ', first_name) LIKE ?", [$like])
                ->orWhere('zemi_number', 'like', '%'.addcslashes($numbers->normalize($term), '%_\\').'%')
                ->orWhere('identification_number', 'like', $like)
                ->orWhereHas('currentAssignment.motorcycle', fn (Builder $m) => $m->where('plate_number', 'like', $plate));

            if (strlen((string) $digits) >= 4) {
                $q->orWhereRaw("REGEXP_REPLACE(phone, '[^0-9]', '') LIKE ?", ['%'.$digits.'%']);
            }
        });
    }
}
