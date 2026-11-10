<?php

namespace Tests\Concerns;

use App\Enums\DocumentType;
use App\Enums\DocumentValidity;
use App\Models\Document;
use App\Models\Driver;
use App\Models\Motorcycle;
use App\Models\User;
use App\Services\AssignmentService;
use App\Services\DocumentService;
use App\Services\DriverValidationService;
use Illuminate\Http\UploadedFile;

/**
 * Construction rapide des dossiers de test (par les services, comme l'API).
 */
trait BuildsRegistry
{
    protected ?User $manager = null;

    protected function manager(): User
    {
        return $this->manager ??= $this->userWithRole('gestionnaire');
    }

    protected function pendingDriver(array $attributes = []): Driver
    {
        return Driver::factory()->create(['created_by' => $this->manager()->id] + $attributes);
    }

    protected function motorcycle(array $attributes = []): Motorcycle
    {
        return Motorcycle::factory()->create(['created_by' => $this->manager()->id] + $attributes);
    }

    /**
     * @param  int|null  $expiresInDays  jours avant expiration (négatif = expiré), null = sans date
     */
    protected function addDocument(Driver|Motorcycle $owner, DocumentType $type, ?int $expiresInDays = 400, bool $withFile = false): Document
    {
        $today = DocumentValidity::today();

        return app(DocumentService::class)->add($owner, [
            'type' => $type->value,
            'number' => strtoupper($type->value).'-'.random_int(1000, 9999),
            'issued_at' => $today->copy()->subYears(2)->toDateString(),
            'expires_at' => $expiresInDays === null ? null : $today->copy()->addDays($expiresInDays)->toDateString(),
        ], $withFile ? $this->pdf() : null, $this->manager());
    }

    protected function assign(Driver $driver, Motorcycle $motorcycle, ?string $reason = null): void
    {
        app(AssignmentService::class)->assign($driver->fresh(), $motorcycle, $this->manager(), reason: $reason);
    }

    /**
     * Dossier En attente complet : photo, pièce d'identité, permis, moto assurée.
     */
    protected function completeDriver(): Driver
    {
        $driver = $this->pendingDriver(['photo_path' => 'drivers/photos/test.jpg']);
        $this->addDocument($driver, DocumentType::IdCard);
        $this->addDocument($driver, DocumentType::DrivingLicense);
        $this->addDocument($driver, DocumentType::TaxiLicense);
        $moto = $this->motorcycle();
        $this->addDocument($moto, DocumentType::Insurance);
        $this->assign($driver, $moto);

        return $driver->fresh();
    }

    protected function activeDriver(): Driver
    {
        return app(DriverValidationService::class)->validate($this->completeDriver(), $this->manager())->fresh();
    }

    protected function pdf(string $name = 'document.pdf'): UploadedFile
    {
        return $this->upload($name, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");
    }

    /**
     * Vrai fichier envoyé (et non UploadedFile::fake(), dont le type MIME est
     * déduit du NOM) : le type est lu dans le contenu par finfo, comme en production.
     */
    protected function upload(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'zc');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }
}
