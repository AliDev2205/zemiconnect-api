<?php

namespace Tests\Feature\Drivers;

use App\Enums\QrStatus;
use App\Models\QrCode;
use App\Services\ZemiNumberGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsRegistry;
use Tests\TestCase;

/**
 * Parcours P1 / GE3 de bout en bout, uniquement par l'API :
 * identité -> photo -> moto (créée) + assurance -> documents -> validation.
 */
class DriverRegistrationFlowTest extends TestCase
{
    use BuildsRegistry, RefreshDatabase;

    public function test_enregistrement_complet_jusqu_au_numero_et_au_qr(): void
    {
        Storage::fake('local');
        $this->actingAs($this->manager());

        // 1. Identité : dossier En attente, avec la fiche papier scannée.
        $driverId = $this->post('/api/v1/drivers', [
            'last_name' => 'TANKO',
            'first_name' => 'Fousséni',
            'birth_date' => '1992-11-19',
            'phone' => '+229 01 97 02 14 78',
            'address' => 'Quartier Dépôt, Parakou',
            'registration_form' => $this->pdf('fiche.pdf'),
        ])->assertCreated()->assertJsonPath('data.status', 'en_attente')->json('data.id');

        // Validation prématurée : la liste de contrôle indique ce qui manque.
        $this->postJson("/api/v1/drivers/{$driverId}/validate")
            ->assertStatus(422)
            ->assertJsonPath('errors.activation', ['photo', 'id_card', 'driving_license', 'motorcycle']);

        // 2. Photo.
        $this->post("/api/v1/drivers/{$driverId}/photo", ['photo' => UploadedFile::fake()->image('photo.jpg', 600, 600)])->assertOk();

        // 3. Moto créée, assurée, affectée.
        $motoId = $this->postJson('/api/v1/motorcycles', ['plate_number' => 'BB 6618 RB', 'brand' => 'TVS', 'color' => 'Noir'])->assertCreated()->json('data.id');
        $this->post("/api/v1/motorcycles/{$motoId}/documents", ['type' => 'insurance', 'expires_at' => now()->addDays(150)->toDateString(), 'file' => $this->pdf()])->assertCreated();
        $this->postJson("/api/v1/drivers/{$driverId}/assignment", ['motorcycle_id' => $motoId])->assertOk();

        // 4. Documents du conducteur (droit de taxi facultatif).
        $this->post("/api/v1/drivers/{$driverId}/documents", ['type' => 'id_card', 'number' => 'CIP-1', 'file' => UploadedFile::fake()->image('cni.jpg')])->assertCreated();
        $this->post("/api/v1/drivers/{$driverId}/documents", ['type' => 'driving_license', 'number' => 'PC-1', 'expires_at' => now()->addYears(5)->toDateString()])->assertCreated();
        $this->post("/api/v1/drivers/{$driverId}/documents", ['type' => 'taxi_license', 'number' => 'DT-1', 'expires_at' => now()->addDays(320)->toDateString()])->assertCreated();

        // 5. Validation.
        $response = $this->postJson("/api/v1/drivers/{$driverId}/validate")->assertOk();

        $response->assertJsonPath('data.status', 'actif')
            ->assertJsonPath('data.compliance_status', 'conforme')
            ->assertJsonPath('data.motorcycle.plate_number', 'BB6618RB');

        $number = $response->json('data.zemi_number');
        $this->assertMatchesRegularExpression('/^ZC-\d{7}$/', $number);
        $this->assertTrue(app(ZemiNumberGenerator::class)->isValid($number), "Clé de Luhn invalide : {$number}");

        $qr = QrCode::query()->where('driver_id', $driverId)->sole();
        $this->assertSame(QrStatus::Active, $qr->status);

        $documents = collect($response->json('data.documents'))->pluck('type')->sort()->values()->all();
        $this->assertSame(['driving_license', 'id_card', 'registration_form', 'taxi_license'], $documents);
        $this->assertNotNull($response->json('data.photo_url'));
    }
}
