<?php

namespace Tests\Feature\Drivers;

use App\Enums\DocumentType;
use App\Models\Activity;
use App\Models\Document;
use App\Models\Driver;
use App\Services\DriverStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsRegistry;
use Tests\TestCase;

/**
 * GE2 (liste), GE3 étape Identité (création En attente, doublons), GE4 (modification, photo).
 */
class DriverTest extends TestCase
{
    use BuildsRegistry, RefreshDatabase;

    private function identity(array $overrides = []): array
    {
        return array_merge([
            'last_name' => 'ALASSANE',
            'first_name' => 'Moussa',
            'birth_date' => '1984-05-14',
            'phone' => '+229 01 96 12 34 56',
            'address' => 'Quartier Titirou, Parakou',
            'identification_number' => 'CIP-229-48190',
        ], $overrides);
    }

    // ── Création ─────────────────────────────────────────────────────────

    public function test_creation_en_attente_des_l_etape_identite(): void
    {
        $response = $this->actingAs($this->manager())->postJson('/api/v1/drivers', $this->identity());

        $response->assertCreated()
            ->assertJsonPath('data.status', 'en_attente')
            ->assertJsonPath('data.zemi_number', null)
            ->assertJsonPath('data.compliance_status', 'non_conforme')
            ->assertJsonPath('data.identification_number', 'CIP-229-48190')
            ->assertJsonPath('data.photo_url', null)
            ->assertJsonPath('data.motorcycle', null)
            ->assertJsonPath('data.documents', []);

        $driver = Driver::query()->findOrFail($response->json('data.id'));
        $this->assertSame($this->manager()->id, $driver->created_by);

        $log = Activity::query()->where('event', 'driver.created')->firstOrFail();
        $this->assertSame('Moussa', $log->properties['attributes']['first_name']);
        $this->assertSame($this->manager()->id, $log->causer_id);
        $this->assertNotNull($log->ip_address);
    }

    public function test_identifiant_et_fiche_papier_facultatifs(): void
    {
        $this->actingAs($this->manager())
            ->postJson('/api/v1/drivers', $this->identity(['identification_number' => null]))
            ->assertCreated()
            ->assertJsonPath('data.identification_number', null);
    }

    public function test_fiche_papier_jointe_devient_un_document_registration_form(): void
    {
        Storage::fake('local');

        $response = $this->actingAs($this->manager())
            ->post('/api/v1/drivers', $this->identity() + ['registration_form' => $this->pdf('fiche.pdf')]);

        $response->assertCreated()
            ->assertJsonPath('data.documents.0.type', 'registration_form')
            ->assertJsonPath('data.documents.0.has_file', true)
            ->assertJsonMissingPath('data.documents.0.file_path');
    }

    public function test_champs_obligatoires_de_l_identite(): void
    {
        $this->actingAs($this->manager())
            ->postJson('/api/v1/drivers', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['last_name', 'first_name', 'birth_date', 'phone', 'address']);
    }

    public function test_telephone_invalide_et_naissance_future_refuses(): void
    {
        $this->actingAs($this->manager())
            ->postJson('/api/v1/drivers', $this->identity(['phone' => 'abc', 'birth_date' => now()->addDay()->toDateString()]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['phone', 'birth_date']);
    }

    // ── Doublons : avertissement, jamais bloquant ───────────────────────

    public function test_doublons_par_telephone_identifiant_et_nom(): void
    {
        $byPhone = $this->pendingDriver(['phone' => '+229 01 96 12 34 56']);
        $byIdentifier = $this->pendingDriver(['identification_number' => 'CIP-229-48190']);
        $byName = $this->pendingDriver(['first_name' => 'Moussa', 'last_name' => 'ALASSANE']);
        $this->pendingDriver(); // sans rapport

        $response = $this->actingAs($this->manager())->getJson('/api/v1/drivers/duplicates?'.http_build_query([
            'phone' => '+22901 96-12-34-56',
            'identification_number' => 'CIP-229-48190',
            'first_name' => 'moussa',
            'last_name' => 'Alassane',
        ]));

        $response->assertOk();
        $found = collect($response->json('data'))->keyBy('id');
        $this->assertCount(3, $found);
        $this->assertSame(['phone'], $found[$byPhone->id]['reasons']);
        $this->assertSame(['identifier'], $found[$byIdentifier->id]['reasons']);
        $this->assertSame(['name'], $found[$byName->id]['reasons']);
    }

    public function test_doublons_exclut_le_dossier_en_cours_de_modification(): void
    {
        $driver = $this->pendingDriver(['phone' => '+229 01 00 00 00 01']);

        $this->actingAs($this->manager())
            ->getJson('/api/v1/drivers/duplicates?'.http_build_query(['phone' => '+229 01 00 00 00 01', 'exclude' => $driver->id]))
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    public function test_un_doublon_ne_bloque_jamais_l_enregistrement(): void
    {
        $this->pendingDriver(['phone' => '+229 01 96 12 34 56', 'first_name' => 'Moussa', 'last_name' => 'ALASSANE']);

        $this->actingAs($this->manager())->postJson('/api/v1/drivers', $this->identity())->assertCreated();

        $this->assertSame(2, Driver::query()->where('last_name', 'ALASSANE')->count());
    }

    // ── Modification ─────────────────────────────────────────────────────

    public function test_modification_journalisee_avec_avant_apres(): void
    {
        $driver = $this->pendingDriver(['address' => 'Ancienne adresse']);

        $this->actingAs($this->manager())
            ->putJson("/api/v1/drivers/{$driver->id}", ['address' => 'Quartier Zongo, Parakou'])
            ->assertOk()
            ->assertJsonPath('data.address', 'Quartier Zongo, Parakou');

        $log = Activity::query()->where('event', 'driver.updated')->firstOrFail();
        $this->assertSame(['address' => 'Ancienne adresse'], $log->properties['old']);
        $this->assertSame(['address' => 'Quartier Zongo, Parakou'], $log->properties['attributes']);
        $this->assertSame($this->manager()->id, $driver->fresh()->updated_by);
    }

    public function test_modification_en_multipart_avec_method_put(): void
    {
        $driver = $this->pendingDriver();

        $this->actingAs($this->manager())
            ->post("/api/v1/drivers/{$driver->id}?_method=PUT", ['first_name' => 'Ibrahim'])
            ->assertOk()
            ->assertJsonPath('data.first_name', 'Ibrahim');
    }

    public function test_dossier_archive_non_modifiable(): void
    {
        $driver = $this->activeDriver();
        app(DriverStatusService::class)->archive($driver, 'Cessation', $this->manager());

        $this->actingAs($this->manager())
            ->putJson("/api/v1/drivers/{$driver->id}", ['address' => 'X'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'driver_archived');
    }

    public function test_conducteur_inconnu_404(): void
    {
        $this->actingAs($this->manager())->getJson('/api/v1/drivers/999999')->assertNotFound();
    }

    // ── Liste, recherche, filtres, tri ───────────────────────────────────

    public function test_recherche_unique_sur_tous_les_champs(): void
    {
        $active = $this->activeDriver();
        $active->forceFill(['last_name' => 'SALIFOU', 'first_name' => 'Ibrahim', 'phone' => '+229 01 95 22 11 08', 'identification_number' => 'REG-PKU-0217'])->save();
        $plate = $active->currentAssignment->motorcycle->plate_number;
        $this->pendingDriver(['last_name' => 'AUTRE', 'first_name' => 'Personne', 'phone' => '+229 01 11 11 11 11']);

        foreach (['salifou', 'Ibrahim', 'Ibrahim SALIFOU', $active->zemi_number, strtolower(str_replace('-', ' ', $active->zemi_number)), '95 22 11', 'REG-PKU', strtolower($plate)] as $term) {
            $ids = collect($this->actingAs($this->manager())->getJson('/api/v1/drivers?search='.urlencode($term))->assertOk()->json('data.data'))->pluck('id')->all();
            $this->assertSame([$active->id], $ids, "Recherche « {$term} »");
        }
    }

    public function test_filtres_statut_conformite_periode_et_tri(): void
    {
        $old = $this->pendingDriver(['created_at' => now()->subDays(40)]);
        $recent = $this->pendingDriver(['created_at' => now()->subDays(2)]);
        $active = $this->activeDriver();

        $get = fn (string $query) => collect($this->actingAs($this->manager())->getJson('/api/v1/drivers?'.$query)->assertOk()->json('data.data'))->pluck('id')->all();

        $this->assertSame([$active->id], $get('status=actif'));
        $this->assertEqualsCanonicalizing([$old->id, $recent->id], $get('status=en_attente'));
        $this->assertSame([$active->id], $get('compliance_status=conforme'));
        $this->assertSame([$old->id], $get('registered_to='.now()->subDays(30)->toDateString()));
        $this->assertSame([$active->id, $recent->id, $old->id], $get('sort=-created_at'));
        $this->assertSame([$old->id, $recent->id, $active->id], $get('sort=created_at'));
    }

    public function test_pagination_au_format_commun(): void
    {
        foreach (range(1, 3) as $i) {
            $this->pendingDriver();
        }

        $this->actingAs($this->manager())->getJson('/api/v1/drivers?per_page=2&page=2')
            ->assertOk()
            ->assertJsonPath('data.total', 3)
            ->assertJsonPath('data.per_page', 2)
            ->assertJsonPath('data.current_page', 2)
            ->assertJsonPath('data.last_page', 2)
            ->assertJsonCount(1, 'data.data');
    }

    // ── Photo ────────────────────────────────────────────────────────────

    public function test_photo_envoyee_reencodee_et_servie_par_url_signee(): void
    {
        Storage::fake('local');
        $driver = $this->pendingDriver();

        $response = $this->actingAs($this->manager())
            ->post("/api/v1/drivers/{$driver->id}/photo", ['photo' => UploadedFile::fake()->image('photo.png', 600, 600)]);

        $response->assertOk();
        $path = $driver->fresh()->photo_path;
        $this->assertStringStartsWith('drivers/photos/', $path);
        $this->assertStringEndsWith('.jpg', $path);
        Storage::disk('local')->assertExists($path);
        $this->assertSame('image/jpeg', Storage::disk('local')->mimeType($path));

        $url = $response->json('data.photo_url');
        $this->assertStringContainsString('signature=', $url);
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->get(strtok($url, '?'))->assertForbidden(); // sans signature
    }

    public function test_remplacement_de_photo_conserve_l_ancien_fichier(): void
    {
        Storage::fake('local');
        $driver = $this->pendingDriver();

        $this->actingAs($this->manager())->post("/api/v1/drivers/{$driver->id}/photo", ['photo' => UploadedFile::fake()->image('a.jpg', 600, 600)])->assertOk();
        $first = $driver->fresh()->photo_path;
        $this->actingAs($this->manager())->post("/api/v1/drivers/{$driver->id}/photo", ['photo' => UploadedFile::fake()->image('b.jpg', 600, 600)])->assertOk();

        $this->assertNotSame($first, $driver->fresh()->photo_path);
        Storage::disk('local')->assertExists($first);
    }

    public function test_photo_de_type_invalide_refusee(): void
    {
        Storage::fake('local');
        $driver = $this->pendingDriver();

        $this->actingAs($this->manager())
            ->post("/api/v1/drivers/{$driver->id}/photo", ['photo' => $this->upload('photo.jpg', 'ceci n\'est pas une image')])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['photo']);

        $this->assertNull($driver->fresh()->photo_path);
    }

    public function test_les_documents_actuels_figurent_sur_la_fiche(): void
    {
        $driver = $this->completeDriver();
        $this->addDocument($driver, DocumentType::DrivingLicense, 900); // renouvellement

        $response = $this->actingAs($this->manager())->getJson("/api/v1/drivers/{$driver->id}")->assertOk();

        $types = collect($response->json('data.documents'))->pluck('type')->sort()->values()->all();
        $this->assertSame(['driving_license', 'id_card', 'taxi_license'], $types);
        $this->assertSame('insurance', $response->json('data.motorcycle.documents.0.type'));
        $this->assertSame(1, Document::query()->where('driver_id', $driver->id)->where('type', 'driving_license')->where('is_current', false)->count());
    }
}
