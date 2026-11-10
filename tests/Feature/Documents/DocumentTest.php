<?php

namespace Tests\Feature\Documents;

use App\Enums\DocumentType;
use App\Models\Activity;
use App\Models\Document;
use App\Services\DriverStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsRegistry;
use Tests\TestCase;

/**
 * Documents (RG07, RG11, §17 fichiers).
 */
class DocumentTest extends TestCase
{
    use BuildsRegistry, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function send(string $url, array $data)
    {
        return $this->actingAs($this->manager())->post($url, $data);
    }

    // ── Ajout et renouvellement ──────────────────────────────────────────

    public function test_ajout_d_un_permis_avec_fichier_prive_au_nom_aleatoire(): void
    {
        $driver = $this->pendingDriver();

        $response = $this->send("/api/v1/drivers/{$driver->id}/documents", [
            'type' => 'driving_license',
            'number' => 'PC-2024-418302',
            'issued_at' => '2024-01-10',
            'expires_at' => '2034-01-09',
            'file' => $this->pdf('mon-permis.pdf'),
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.type', 'driving_license')
            ->assertJsonPath('data.driver_id', $driver->id)
            ->assertJsonPath('data.motorcycle_id', null)
            ->assertJsonPath('data.is_current', true)
            ->assertJsonPath('data.has_file', true)
            ->assertJsonPath('data.file_mime', 'application/pdf')
            ->assertJsonPath('data.validity', 'valide')
            ->assertJsonMissingPath('data.file_path');

        $path = Document::query()->sole()->file_path;
        $this->assertStringNotContainsString('mon-permis', $path);
        $this->assertMatchesRegularExpression('#^documents/\d{4}/\d{2}/[A-Za-z0-9]{40}\.pdf$#', $path);
        Storage::disk('local')->assertExists($path);
        $this->assertNotNull(Activity::query()->where('event', 'document.added')->first());
    }

    public function test_renouvellement_l_ancien_passe_en_historique_rien_n_est_supprime(): void
    {
        $driver = $this->pendingDriver();
        $old = $this->addDocument($driver, DocumentType::TaxiLicense, 10);

        $this->send("/api/v1/drivers/{$driver->id}/documents", ['type' => 'taxi_license', 'expires_at' => now()->addYear()->toDateString()])
            ->assertCreated()
            ->assertJsonPath('data.is_current', true);

        $this->assertFalse($old->fresh()->is_current);
        $this->assertSame(2, Document::query()->where('driver_id', $driver->id)->count());

        $history = $this->actingAs($this->manager())->getJson("/api/v1/drivers/{$driver->id}/documents/history?type=taxi_license")->assertOk()->json('data');
        $this->assertSame([$old->id], array_column($history, 'id'));

        $log = Activity::query()->where('event', 'document.renewed')->sole();
        $this->assertSame($old->expires_at->toDateString(), $log->properties['old']['expires_at']);
    }

    public function test_historique_par_type(): void
    {
        $driver = $this->pendingDriver();
        $this->addDocument($driver, DocumentType::DrivingLicense);
        $this->addDocument($driver, DocumentType::DrivingLicense);
        $this->addDocument($driver, DocumentType::IdCard);
        $this->addDocument($driver, DocumentType::IdCard);

        $all = $this->actingAs($this->manager())->getJson("/api/v1/drivers/{$driver->id}/documents/history")->assertOk()->json('data');
        $this->assertCount(2, $all);

        $licenses = $this->actingAs($this->manager())->getJson("/api/v1/drivers/{$driver->id}/documents/history?type=driving_license")->json('data');
        $this->assertSame(['driving_license'], array_unique(array_column($licenses, 'type')));
    }

    public function test_assurance_de_la_moto(): void
    {
        $moto = $this->motorcycle();

        $this->send("/api/v1/motorcycles/{$moto->id}/documents", ['type' => 'insurance', 'issuer' => 'Assureur SA', 'expires_at' => now()->addDays(20)->toDateString()])
            ->assertCreated()
            ->assertJsonPath('data.motorcycle_id', $moto->id)
            ->assertJsonPath('data.validity', 'expire_bientot');

        $this->send("/api/v1/motorcycles/{$moto->id}/documents", ['type' => 'insurance', 'expires_at' => now()->addYear()->toDateString()])->assertCreated();
        $this->assertCount(1, $this->actingAs($this->manager())->getJson("/api/v1/motorcycles/{$moto->id}/documents/history")->json('data'));
    }

    // ── Rattachement et dates ────────────────────────────────────────────

    public function test_assurance_sur_un_conducteur_422_lisible(): void
    {
        $this->send("/api/v1/drivers/{$this->pendingDriver()->id}/documents", ['type' => 'insurance', 'expires_at' => '2030-01-01'])
            ->assertStatus(422)
            ->assertJsonPath('errors.type.0', 'L\'assurance se rattache à la moto, pas au conducteur.');
    }

    public function test_permis_sur_une_moto_422_lisible(): void
    {
        $this->send("/api/v1/motorcycles/{$this->motorcycle()->id}/documents", ['type' => 'driving_license'])
            ->assertStatus(422)
            ->assertJsonPath('errors.type.0', 'Seule l\'assurance se rattache à la moto.');
    }

    public function test_expiration_obligatoire_pour_l_assurance_seulement(): void
    {
        $this->send("/api/v1/motorcycles/{$this->motorcycle()->id}/documents", ['type' => 'insurance', 'expires_at' => ''])
            ->assertStatus(422)
            ->assertJsonPath('errors.expires_at.0', 'La date d\'expiration est obligatoire pour l\'assurance.');

        $this->send("/api/v1/drivers/{$this->pendingDriver()->id}/documents", ['type' => 'driving_license', 'expires_at' => ''])
            ->assertCreated()
            ->assertJsonPath('data.expires_at', null)
            ->assertJsonPath('data.validity', 'valide');
    }

    public function test_expiration_posterieure_a_la_delivrance(): void
    {
        $this->send("/api/v1/drivers/{$this->pendingDriver()->id}/documents", ['type' => 'driving_license', 'issued_at' => '2024-05-01', 'expires_at' => '2024-05-01'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['expires_at']);
    }

    public function test_fiche_papier_exige_un_fichier(): void
    {
        $this->send("/api/v1/drivers/{$this->pendingDriver()->id}/documents", ['type' => 'registration_form'])
            ->assertStatus(422)->assertJsonValidationErrors(['file']);
    }

    // ── Fichiers : type réel et taille ───────────────────────────────────

    /**
     * Vrais fichiers (type lu dans le contenu par finfo).
     */
    public static function invalidFiles(): iterable
    {
        yield 'texte renommé en .pdf' => ['permis.pdf', 'du texte, pas un PDF'];
        yield 'image GIF renommée en .png' => ['permis.png', base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7')];
        yield 'script PHP renommé en .jpg' => ['permis.jpg', "<?php echo 'piège';"];
        yield 'PDF réel avec extension .exe' => ['permis.exe', "%PDF-1.4\n%%EOF\n"];
        yield 'fichier de plus de 5 Mo' => ['gros.pdf', "%PDF-1.4\n".str_repeat('0', 5 * 1024 * 1024 + 10)];
    }

    #[DataProvider('invalidFiles')]
    public function test_fichier_refuse(string $name, string $content): void
    {
        $this->send("/api/v1/drivers/{$this->pendingDriver()->id}/documents", ['type' => 'id_card', 'file' => $this->upload($name, $content)])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['file']);

        $this->assertSame(0, Document::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_jpeg_et_png_acceptes(): void
    {
        $driver = $this->pendingDriver();

        $this->send("/api/v1/drivers/{$driver->id}/documents", ['type' => 'id_card', 'file' => UploadedFile::fake()->image('cni.jpg')])->assertCreated()->assertJsonPath('data.file_mime', 'image/jpeg');
        $this->send("/api/v1/drivers/{$driver->id}/documents", ['type' => 'id_card', 'file' => UploadedFile::fake()->image('cni.png')])->assertCreated()->assertJsonPath('data.file_mime', 'image/png');
    }

    public function test_dossier_archive_n_accepte_plus_de_document(): void
    {
        $driver = $this->activeDriver();
        app(DriverStatusService::class)->archive($driver, 'Fin', $this->manager());

        $this->send("/api/v1/drivers/{$driver->id}/documents", ['type' => 'id_card'])
            ->assertStatus(422)->assertJsonPath('code', 'driver_archived');
    }

    // ── Accès aux fichiers par rôle ──────────────────────────────────────

    public static function roles(): iterable
    {
        yield 'gestionnaire' => ['gestionnaire', 200];
        yield 'administrateur (lecture, fichiers compris)' => ['admin', 200];
        yield 'agent (jamais)' => ['agent', 403];
    }

    #[DataProvider('roles')]
    public function test_acces_au_fichier_par_role(string $role, int $expected): void
    {
        $document = $this->addDocument($this->pendingDriver(), DocumentType::DrivingLicense, withFile: true);
        $user = $role === 'gestionnaire' ? $this->manager() : $this->userWithRole($role);

        $response = $this->actingAs($user)->getJson("/api/v1/documents/{$document->id}/file")->assertStatus($expected);

        if ($expected === 200) {
            $url = $response->json('data.url');
            $this->assertStringContainsString('signature=', $url);
            $this->assertNotNull($response->json('data.expires_at'));

            $file = $this->get($url);
            $file->assertOk()->assertHeader('Content-Type', 'application/pdf');
            $this->assertStringStartsWith('%PDF', $file->streamedContent());
            $this->assertSame($user->id, Activity::query()->where('event', 'document.file_viewed')->sole()->causer_id);
        }
    }

    public function test_visiteur_non_connecte_401(): void
    {
        $document = $this->addDocument($this->pendingDriver(), DocumentType::DrivingLicense, withFile: true);

        $this->getJson("/api/v1/documents/{$document->id}/file")->assertUnauthorized();
    }

    public function test_telechargement_sans_signature_ou_expire_refuse(): void
    {
        $document = $this->addDocument($this->pendingDriver(), DocumentType::DrivingLicense, withFile: true);
        $url = $this->actingAs($this->manager())->getJson("/api/v1/documents/{$document->id}/file")->json('data.url');

        $this->get(strtok($url, '?'))->assertForbidden();
        $this->get(str_replace('signature=', 'signature=x', $url))->assertForbidden();

        $this->travel(6)->minutes();
        $this->get($url)->assertForbidden();
    }

    public function test_document_sans_fichier_404(): void
    {
        $document = $this->addDocument($this->pendingDriver(), DocumentType::DrivingLicense);

        $this->actingAs($this->manager())->getJson("/api/v1/documents/{$document->id}/file")->assertNotFound();
    }

    public static function nonWriters(): iterable
    {
        yield 'administrateur' => ['admin'];
        yield 'agent' => ['agent'];
    }

    #[DataProvider('nonWriters')]
    public function test_agent_et_administrateur_ne_peuvent_pas_ajouter_de_document(string $role): void
    {
        $driver = $this->pendingDriver();

        $this->actingAs($this->userWithRole($role))
            ->postJson("/api/v1/drivers/{$driver->id}/documents", ['type' => 'id_card'])
            ->assertForbidden();

        $this->assertSame(0, Document::query()->count());
    }
}
