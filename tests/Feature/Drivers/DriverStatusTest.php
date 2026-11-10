<?php

namespace Tests\Feature\Drivers;

use App\Enums\DocumentType;
use App\Enums\DriverStatus;
use App\Enums\QrStatus;
use App\Models\Activity;
use App\Models\Driver;
use App\Models\QrCode;
use App\Services\ZemiNumberGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsRegistry;
use Tests\TestCase;

/**
 * Validation (RG02, RG04, RG15) et transitions de statut (GE4).
 */
class DriverStatusTest extends TestCase
{
    use BuildsRegistry, RefreshDatabase;

    // ── Validation ───────────────────────────────────────────────────────

    public function test_validation_incomplete_422_avec_la_liste_des_elements_manquants(): void
    {
        $driver = $this->pendingDriver();

        $this->actingAs($this->manager())->postJson("/api/v1/drivers/{$driver->id}/validate")
            ->assertStatus(422)
            ->assertJsonPath('code', 'activation_incomplete')
            ->assertJsonPath('errors.activation', ['photo', 'id_card', 'driving_license', 'motorcycle']);

        $driver->refresh();
        $this->assertSame(DriverStatus::EnAttente, $driver->status);
        $this->assertNull($driver->zemi_number);
        $this->assertSame(0, QrCode::query()->count());
    }

    public function test_validation_partiellement_incomplete(): void
    {
        $driver = $this->completeDriver();
        $driver->forceFill(['photo_path' => null])->save();

        $this->actingAs($this->manager())->postJson("/api/v1/drivers/{$driver->id}/validate")
            ->assertStatus(422)
            ->assertJsonPath('errors.activation', ['photo']);
    }

    public function test_validation_attribue_numero_qr_actif_et_statut_actif(): void
    {
        $driver = $this->completeDriver();

        $response = $this->actingAs($this->manager())->postJson("/api/v1/drivers/{$driver->id}/validate");

        $response->assertOk()->assertJsonPath('data.status', 'actif');
        $driver->refresh();
        $this->assertTrue(app(ZemiNumberGenerator::class)->isValid($driver->zemi_number));
        $this->assertSame($response->json('data.zemi_number'), $driver->zemi_number);
        $this->assertSame($this->manager()->id, $driver->validated_by);
        $this->assertNotNull($driver->validated_at);

        $qr = QrCode::query()->where('driver_id', $driver->id)->sole();
        $this->assertSame(QrStatus::Active, $qr->status);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{16}$/', $qr->token);

        $this->assertNotNull(Activity::query()->where('event', 'driver.validated')->first());
        $this->assertNotNull(Activity::query()->where('event', 'qr.generated')->first());
    }

    public function test_la_conformite_n_est_pas_exigee_pour_valider(): void
    {
        $driver = $this->pendingDriver(['photo_path' => 'p.jpg']);
        $this->addDocument($driver, DocumentType::IdCard);
        $this->addDocument($driver, DocumentType::DrivingLicense, -20); // permis expiré
        $this->assign($driver, $this->motorcycle()); // moto sans assurance

        $this->actingAs($this->manager())->postJson("/api/v1/drivers/{$driver->id}/validate")
            ->assertOk()
            ->assertJsonPath('data.status', 'actif')
            ->assertJsonPath('data.compliance_status', 'non_conforme');
    }

    public function test_validation_seulement_depuis_en_attente(): void
    {
        $driver = $this->activeDriver();
        $number = $driver->zemi_number;

        $this->actingAs($this->manager())->postJson("/api/v1/drivers/{$driver->id}/validate")
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_transition');

        $this->assertSame($number, $driver->fresh()->zemi_number);
        $this->assertSame(1, QrCode::query()->where('driver_id', $driver->id)->count());
    }

    // ── Suspendre, réactiver ─────────────────────────────────────────────

    public function test_suspension_avec_motif_puis_reactivation(): void
    {
        $driver = $this->activeDriver();

        $this->actingAs($this->manager())->patchJson("/api/v1/drivers/{$driver->id}/status", ['status' => 'suspendu', 'reason' => 'Plainte d\'un passager'])
            ->assertOk()
            ->assertJsonPath('data.status', 'suspendu')
            ->assertJsonPath('data.status_reason', 'Plainte d\'un passager');

        $this->actingAs($this->manager())->patchJson("/api/v1/drivers/{$driver->id}/status", ['status' => 'actif'])
            ->assertOk()
            ->assertJsonPath('data.status', 'actif')
            ->assertJsonPath('data.status_reason', null);

        $suspended = Activity::query()->where('event', 'driver.suspended')->sole();
        $this->assertSame('actif', $suspended->properties['old']['status']);
        $this->assertSame('suspendu', $suspended->properties['attributes']['status']);
        $this->assertSame('Plainte d\'un passager', $suspended->properties['reason']);
    }

    public function test_suspension_sans_motif_422(): void
    {
        $driver = $this->activeDriver();

        $this->actingAs($this->manager())->patchJson("/api/v1/drivers/{$driver->id}/status", ['status' => 'suspendu'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);
    }

    public function test_transitions_interdites_422_invalid_transition(): void
    {
        $pending = $this->pendingDriver();
        $active = $this->activeDriver();

        // Suspendre un dossier En attente, réactiver un dossier Actif.
        $this->actingAs($this->manager())->patchJson("/api/v1/drivers/{$pending->id}/status", ['status' => 'suspendu', 'reason' => 'x'])
            ->assertStatus(422)->assertJsonPath('code', 'invalid_transition');
        $this->actingAs($this->manager())->patchJson("/api/v1/drivers/{$active->id}/status", ['status' => 'actif'])
            ->assertStatus(422)->assertJsonPath('code', 'invalid_transition');
        // Archiver un dossier En attente : interdit par le CHECK C13 (pas de numéro), voir README.
        $this->actingAs($this->manager())->postJson("/api/v1/drivers/{$pending->id}/archive", ['reason' => 'x'])
            ->assertStatus(422)->assertJsonPath('code', 'invalid_transition');
        // Statut cible inconnu.
        $this->actingAs($this->manager())->patchJson("/api/v1/drivers/{$active->id}/status", ['status' => 'archive', 'reason' => 'x'])
            ->assertStatus(422)->assertJsonValidationErrors(['status']);
    }

    public function test_aucune_transition_depuis_archive(): void
    {
        $driver = $this->activeDriver();
        $this->actingAs($this->manager())->postJson("/api/v1/drivers/{$driver->id}/archive", ['reason' => 'Cessation'])->assertOk();

        foreach ([['patch', 'status', ['status' => 'actif']], ['patch', 'status', ['status' => 'suspendu', 'reason' => 'x']], ['post', 'archive', ['reason' => 'x']], ['post', 'validate', []]] as [$method, $path, $body]) {
            $this->actingAs($this->manager())->json($method, "/api/v1/drivers/{$driver->id}/{$path}", $body)
                ->assertStatus(422)
                ->assertJsonPath('code', 'invalid_transition');
        }
    }

    // ── Archiver ─────────────────────────────────────────────────────────

    public function test_archivage_revoque_le_qr_et_clot_l_affectation(): void
    {
        $driver = $this->activeDriver();
        $number = $driver->zemi_number;

        $this->actingAs($this->manager())->postJson("/api/v1/drivers/{$driver->id}/archive", ['reason' => 'Cessation d\'activité'])
            ->assertOk()
            ->assertJsonPath('data.status', 'archive')
            ->assertJsonPath('data.motorcycle', null)
            ->assertJsonPath('data.compliance_status', 'non_conforme');

        $driver->refresh();
        $this->assertSame($number, $driver->zemi_number); // le numéro reste réservé
        $this->assertNotNull($driver->archived_at);

        $qr = QrCode::query()->where('driver_id', $driver->id)->sole();
        $this->assertSame(QrStatus::Revoked, $qr->status);
        $this->assertSame('Dossier archivé : Cessation d\'activité', $qr->revoke_reason);

        $assignment = $driver->assignments()->sole();
        $this->assertNotNull($assignment->ended_at);
        $this->assertSame($this->manager()->id, $assignment->ended_by);
    }

    public function test_archivage_sans_motif_422(): void
    {
        $driver = $this->activeDriver();

        $this->actingAs($this->manager())->postJson("/api/v1/drivers/{$driver->id}/archive", [])
            ->assertStatus(422)->assertJsonValidationErrors(['reason']);
        $this->assertSame(DriverStatus::Actif, $driver->fresh()->status);
    }

    public function test_archivage_d_un_dossier_suspendu(): void
    {
        $driver = $this->activeDriver();
        $this->actingAs($this->manager())->patchJson("/api/v1/drivers/{$driver->id}/status", ['status' => 'suspendu', 'reason' => 'x'])->assertOk();

        $this->actingAs($this->manager())->postJson("/api/v1/drivers/{$driver->id}/archive", ['reason' => 'Fin'])->assertOk();
        $this->assertSame(DriverStatus::Archive, Driver::query()->find($driver->id)->status);
    }

    // ── Historique ───────────────────────────────────────────────────────

    public function test_historique_chronologique_avec_avant_apres_motifs_documents_affectations_qr(): void
    {
        $this->actingAs($this->manager());
        $driver = $this->activeDriver();
        $this->patchJson("/api/v1/drivers/{$driver->id}/status", ['status' => 'suspendu', 'reason' => 'Contrôle'])->assertOk();
        $this->postJson("/api/v1/drivers/{$driver->id}/archive", ['reason' => 'Fin d\'activité'])->assertOk();

        $entries = collect($this->getJson("/api/v1/drivers/{$driver->id}/history")->assertOk()->json('data'));
        $actions = $entries->pluck('action');

        foreach (['document.added', 'assignment.changed', 'driver.validated', 'qr.generated', 'driver.suspended', 'driver.archived', 'qr.revoked', 'assignment.closed'] as $action) {
            $this->assertContains($action, $actions, $action);
        }

        $suspended = $entries->firstWhere('action', 'driver.suspended');
        $this->assertSame(['status' => 'actif', 'status_reason' => null], $suspended['old_values']);
        $this->assertSame('Contrôle', $suspended['reason']);
        $this->assertSame('gestionnaire', $suspended['actor_role']);
        $this->assertSame('audit', $suspended['kind']);

        // Plus récent d'abord.
        $dates = $entries->pluck('created_at')->all();
        $sorted = $dates;
        rsort($sorted);
        $this->assertSame($sorted, $dates);
    }
}
