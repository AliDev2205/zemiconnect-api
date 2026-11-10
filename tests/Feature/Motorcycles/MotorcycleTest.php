<?php

namespace Tests\Feature\Motorcycles;

use App\Enums\DocumentType;
use App\Enums\MotorcycleStatus;
use App\Models\Activity;
use App\Models\Motorcycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsRegistry;
use Tests\TestCase;

/**
 * Motos (GE5, GE6, RG10) et affectations (RG08, RG09, P6).
 */
class MotorcycleTest extends TestCase
{
    use BuildsRegistry, RefreshDatabase;

    // ── Motos ────────────────────────────────────────────────────────────

    public function test_creation_avec_immatriculation_normalisee(): void
    {
        $this->actingAs($this->manager())->postJson('/api/v1/motorcycles', [
            'plate_number' => 'bb 4521-rb',
            'brand' => 'Bajaj',
            'model' => 'Boxer BM 150',
            'color' => 'Rouge',
            'manufacture_year' => 2021,
            'chassis_number' => 'md2a21ay5mw894102',
        ])->assertCreated()
            ->assertJsonPath('data.plate_number', 'BB4521RB')
            ->assertJsonPath('data.chassis_number', 'MD2A21AY5MW894102')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.driver_id', null)
            ->assertJsonPath('data.documents', []);

        $this->assertNotNull(Activity::query()->where('event', 'motorcycle.created')->first());
    }

    public function test_immatriculation_unique_apres_normalisation(): void
    {
        $this->motorcycle(['plate_number' => 'BB4521RB']);

        $this->actingAs($this->manager())->postJson('/api/v1/motorcycles', ['plate_number' => 'BB 4521 RB'])
            ->assertStatus(422)
            ->assertJsonPath('errors.plate_number.0', 'Cette immatriculation est déjà enregistrée.');
    }

    public function test_chassis_unique_s_il_est_renseigne(): void
    {
        $this->motorcycle(['chassis_number' => 'ABC123']);

        $this->actingAs($this->manager())->postJson('/api/v1/motorcycles', ['plate_number' => 'BB0001RB', 'chassis_number' => 'abc123'])
            ->assertStatus(422)->assertJsonValidationErrors(['chassis_number']);

        $this->actingAs($this->manager())->postJson('/api/v1/motorcycles', ['plate_number' => 'BB0002RB', 'chassis_number' => ''])->assertCreated();
        $this->actingAs($this->manager())->postJson('/api/v1/motorcycles', ['plate_number' => 'BB0003RB'])->assertCreated();
    }

    public function test_modification_de_sa_propre_immatriculation_autorisee(): void
    {
        $moto = $this->motorcycle(['plate_number' => 'BB4521RB']);

        $this->actingAs($this->manager())->putJson("/api/v1/motorcycles/{$moto->id}", ['plate_number' => 'BB 4521 RB', 'color' => 'Bleu'])
            ->assertOk()
            ->assertJsonPath('data.color', 'Bleu');

        $log = Activity::query()->where('event', 'motorcycle.updated')->sole();
        $this->assertSame(['color' => 'Rouge'], $log->properties['old']);
    }

    public function test_liste_recherche_et_filtre_sans_conducteur(): void
    {
        $free = $this->motorcycle(['plate_number' => 'BB1111RB']);
        $driver = $this->pendingDriver();
        $taken = $this->motorcycle(['plate_number' => 'BB2222RB']);
        $this->assign($driver, $taken);
        $archived = $this->motorcycle(['plate_number' => 'BB3333RB', 'status' => MotorcycleStatus::Archived, 'archived_at' => now()]);

        $ids = fn (string $q) => collect($this->actingAs($this->manager())->getJson('/api/v1/motorcycles?'.$q)->assertOk()->json('data.data'))->pluck('id')->all();

        $this->assertSame([$free->id], $ids('unassigned=true'));
        $this->assertSame([$taken->id], $ids('search='.urlencode('bb 2222')));
        $this->assertSame([$archived->id], $ids('status=archived'));

        $row = collect($this->actingAs($this->manager())->getJson('/api/v1/motorcycles')->json('data.data'))->firstWhere('id', $taken->id);
        $this->assertSame($driver->id, $row['driver_id']);
        $this->assertSame($driver->fullName(), $row['driver_name']);
    }

    public function test_archivage_refuse_si_la_moto_est_affectee(): void
    {
        $moto = $this->motorcycle();
        $this->assign($this->pendingDriver(), $moto);

        $this->actingAs($this->manager())->postJson("/api/v1/motorcycles/{$moto->id}/archive", ['archive_reason' => 'Épave'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'motorcycle_assigned');

        $this->assertSame(MotorcycleStatus::Active, $moto->fresh()->status);
    }

    public function test_archivage_avec_motif_puis_moto_figee(): void
    {
        $moto = $this->motorcycle();

        $this->actingAs($this->manager())->postJson("/api/v1/motorcycles/{$moto->id}/archive", [])
            ->assertStatus(422)->assertJsonValidationErrors(['archive_reason']);

        $this->actingAs($this->manager())->postJson("/api/v1/motorcycles/{$moto->id}/archive", ['archive_reason' => 'Épave'])
            ->assertOk()
            ->assertJsonPath('data.status', 'archived')
            ->assertJsonPath('data.archive_reason', 'Épave');

        $this->actingAs($this->manager())->putJson("/api/v1/motorcycles/{$moto->id}", ['color' => 'Vert'])
            ->assertStatus(422)->assertJsonPath('code', 'motorcycle_archived');
    }

    // ── Affectations ─────────────────────────────────────────────────────

    public function test_premiere_affectation(): void
    {
        $driver = $this->pendingDriver();
        $moto = $this->motorcycle();

        $this->actingAs($this->manager())->postJson("/api/v1/drivers/{$driver->id}/assignment", ['motorcycle_id' => $moto->id])
            ->assertOk()
            ->assertJsonPath('data.motorcycle.id', $moto->id)
            ->assertJsonPath('data.assignment_started_at', now()->toDateString());
    }

    public function test_changement_de_moto_clot_l_ancienne_affectation(): void
    {
        $driver = $this->pendingDriver();
        $first = $this->motorcycle();
        $this->assign($driver, $first);
        $second = $this->motorcycle();

        $this->actingAs($this->manager())->postJson("/api/v1/drivers/{$driver->id}/assignment", [
            'motorcycle_id' => $second->id,
            'started_at' => now()->toDateString(),
            'start_reason' => 'Moto en panne',
        ])->assertOk()->assertJsonPath('data.motorcycle.id', $second->id);

        $history = $this->actingAs($this->manager())->getJson("/api/v1/drivers/{$driver->id}/assignments")->assertOk()->json('data');
        $this->assertCount(2, $history);
        $this->assertSame($second->id, $history[0]['motorcycle_id']);
        $this->assertNull($history[0]['ended_at']);
        $this->assertSame($first->id, $history[1]['motorcycle_id']);
        $this->assertSame(now()->toDateString(), $history[1]['ended_at']);
        $this->assertSame('Moto en panne', $history[1]['end_reason']);
        $this->assertSame($first->plate_number, $history[1]['plate_number']);

        // La première moto est de nouveau libre.
        $this->assertNull($first->fresh()->currentAssignment);

        $byMoto = $this->actingAs($this->manager())->getJson("/api/v1/motorcycles/{$first->id}/assignments")->assertOk()->json('data');
        $this->assertSame($driver->fullName(), $byMoto[0]['driver_name']);
    }

    public function test_changement_sans_motif_refuse(): void
    {
        $driver = $this->pendingDriver();
        $this->assign($driver, $this->motorcycle());

        $this->actingAs($this->manager())->postJson("/api/v1/drivers/{$driver->id}/assignment", ['motorcycle_id' => $this->motorcycle()->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['start_reason']);
    }

    public function test_moto_deja_affectee_a_un_autre_conducteur_422_sur_motorcycle_id(): void
    {
        $moto = $this->motorcycle();
        $this->assign($this->pendingDriver(), $moto);
        $other = $this->pendingDriver();

        $this->actingAs($this->manager())->postJson("/api/v1/drivers/{$other->id}/assignment", ['motorcycle_id' => $moto->id])
            ->assertStatus(422)
            ->assertJsonPath('code', 'motorcycle_unavailable')
            ->assertJsonPath('errors.motorcycle_id.0', 'Cette moto est déjà affectée à un autre conducteur.');

        $this->assertNull($other->fresh()->currentAssignment);
    }

    public function test_moto_archivee_non_affectable(): void
    {
        $moto = $this->motorcycle(['status' => MotorcycleStatus::Archived, 'archived_at' => now()]);

        $this->actingAs($this->manager())->postJson("/api/v1/drivers/{$this->pendingDriver()->id}/assignment", ['motorcycle_id' => $moto->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['motorcycle_id']);
    }

    public function test_date_de_changement_future_refusee(): void
    {
        $driver = $this->pendingDriver();

        $this->actingAs($this->manager())->postJson("/api/v1/drivers/{$driver->id}/assignment", [
            'motorcycle_id' => $this->motorcycle()->id,
            'started_at' => now()->addDays(3)->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors(['started_at']);
    }

    public function test_la_moto_porte_l_assurance_dans_sa_fiche(): void
    {
        $moto = $this->motorcycle();
        $this->addDocument($moto, DocumentType::Insurance, 100);

        $this->actingAs($this->manager())->getJson("/api/v1/motorcycles/{$moto->id}")
            ->assertOk()
            ->assertJsonPath('data.documents.0.type', 'insurance')
            ->assertJsonPath('data.documents.0.validity', 'valide');
    }

    public function test_moto_inconnue_404(): void
    {
        $this->actingAs($this->manager())->getJson('/api/v1/motorcycles/999999')->assertNotFound();
        $this->assertSame(0, Motorcycle::query()->count());
    }
}
