<?php

namespace App\Services;

use App\Enums\DocumentType;
use App\Exceptions\BusinessRuleException;
use App\Models\Document;
use App\Models\Driver;
use App\Models\Motorcycle;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Documents (RG07, RG11). Ajouter un document d'un type qui a déjà un document
 * actuel = renouvellement : l'ancien passe à is_current = 0 et le nouveau est
 * créé, dans une transaction. Rien n'est jamais supprimé (pas même l'ancien fichier).
 * La conformité du conducteur concerné est recalculée.
 */
class DocumentService
{
    public function __construct(private readonly ComplianceService $compliance) {}

    /**
     * @param  array{type: string, number?: ?string, category?: ?string, issuer?: ?string, issued_at?: ?string, expires_at?: ?string}  $data
     */
    public function add(Driver|Motorcycle $owner, array $data, ?UploadedFile $file, User $by): Document
    {
        $type = DocumentType::from($data['type']);
        $stored = $file ? $this->store($file) : null;

        try {
            return DB::transaction(function () use ($owner, $data, $type, $stored, $by) {
                $owner = $owner::query()->lockForUpdate()->findOrFail($owner->id);

                if ($owner instanceof Driver && $owner->isArchived()) {
                    throw BusinessRuleException::driverArchived();
                }
                if ($owner instanceof Motorcycle && $owner->isArchived()) {
                    throw BusinessRuleException::motorcycleArchived('type');
                }

                $ownerColumn = $owner instanceof Driver ? 'driver_id' : 'motorcycle_id';

                $previous = Document::query()
                    ->where($ownerColumn, $owner->id)
                    ->where('type', $type)
                    ->where('is_current', true)
                    ->lockForUpdate()
                    ->first();

                $previous?->forceFill(['is_current' => false, 'updated_by' => $by->id])->save();

                $document = Document::query()->create([
                    $ownerColumn => $owner->id,
                    'type' => $type,
                    'number' => $data['number'] ?? null,
                    'category' => $data['category'] ?? null,
                    'issuer' => $data['issuer'] ?? null,
                    'issued_at' => $data['issued_at'] ?? null,
                    'expires_at' => $data['expires_at'] ?? null,
                    'file_path' => $stored['path'] ?? null,
                    'file_mime' => $stored['mime'] ?? null,
                    'file_size' => $stored['size'] ?? null,
                    'is_current' => true,
                    'created_by' => $by->id,
                ]);

                $driver = $owner instanceof Driver ? $owner : $owner->currentAssignment()->first()?->driver;

                Audit::record(
                    $previous ? 'document.renewed' : 'document.added',
                    $document,
                    $previous ? $this->summary($previous) : [],
                    $this->summary($document),
                    driverId: $driver?->id,
                    label: $driver?->fullName() ?? $owner->plate_number ?? null,
                    detail: $type->value,
                );

                if ($driver !== null) {
                    $this->compliance->recalculate($driver);
                }

                return $document;
            });
        } catch (Throwable $e) {
            // La transaction est annulée : le fichier déposé ne correspond à aucune ligne, on le retire.
            if ($stored !== null) {
                Storage::disk(config('zemiconnect.files.disk'))->delete($stored['path']);
            }
            throw $e;
        }
    }

    /**
     * Dépôt sur le disque privé, sous un nom aléatoire (jamais le nom d'origine).
     *
     * @return array{path: string, mime: string, size: int}
     */
    public function store(UploadedFile $file, string $directory = 'documents'): array
    {
        $extension = match ($file->getMimeType()) {
            'application/pdf' => 'pdf',
            'image/png' => 'png',
            default => 'jpg',
        };
        $name = Str::random(40).'.'.$extension;
        $path = $file->storeAs($directory.'/'.now()->format('Y/m'), $name, config('zemiconnect.files.disk'));

        return ['path' => $path, 'mime' => (string) $file->getMimeType(), 'size' => (int) $file->getSize()];
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Document $document): array
    {
        return [
            'type' => $document->type,
            'number' => $document->number,
            'issued_at' => $document->issued_at,
            'expires_at' => $document->expires_at,
            'has_file' => $document->file_path !== null,
        ];
    }
}
