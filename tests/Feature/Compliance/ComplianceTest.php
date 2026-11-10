<?php

namespace Tests\Feature\Compliance;

use App\Enums\ComplianceStatus;
use App\Enums\DocumentType;
use App\Enums\DocumentValidity;
use App\Enums\DriverStatus;
use App\Models\Driver;
use App\Services\ComplianceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsRegistry;
use Tests\TestCase;

/**
 * RG05, RG06, RG07, B3 : permis, droit de taxi, assurance de la moto actuelle.
 */
class ComplianceTest extends TestCase
{
    use BuildsRegistry, RefreshDatabase;

    /**
     * Conducteur dont les trois documents expirent dans les délais donnés (null = sans date).
     */
    private function driverWith(?int $license = 400, ?int $taxi = 400, ?int $insurance = 400): Driver
    {
        $driver = $this->pendingDriver();
        $this->addDocument($driver, DocumentType::DrivingLicense, $license);
        $this->addDocument($driver, DocumentType::TaxiLicense, $taxi);
        $moto = $this->motorcycle();
        $this->addDocument($moto, DocumentType::Insurance, $insurance ?? 400);
        $this->assign($driver, $moto);

        return $driver->fresh();
    }

    private function compliance(Driver $driver): ComplianceStatus
    {
        return app(ComplianceService::class)->compute($driver);
    }

    public function test_tout_valide_conforme(): void
    {
        $driver = $this->driverWith();

        $this->assertSame(ComplianceStatus::Conforme, $this->compliance($driver));
        $this->assertSame(ComplianceStatus::Conforme, $driver->compliance_status); // recalculé à l'écriture
    }

    public function test_expiration_a_j_moins_30_expire_bientot(): void
    {
        $this->assertSame(ComplianceStatus::ExpireBientot, $this->compliance($this->driverWith(license: 30)));
    }

    public function test_expiration_a_j_moins_31_conforme(): void
    {
        $this->assertSame(ComplianceStatus::Conforme, $this->compliance($this->driverWith(taxi: 31)));
    }

    public function test_expiration_le_jour_meme_expire_bientot_pas_encore_expire(): void
    {
        $this->assertSame(ComplianceStatus::ExpireBientot, $this->compliance($this->driverWith(insurance: 0)));
    }

    public function test_expire_depuis_hier_non_conforme(): void
    {
        $this->assertSame(ComplianceStatus::NonConforme, $this->compliance($this->driverWith(license: -1)));
    }

    public function test_sans_date_d_expiration_n_est_pas_non_conforme(): void
    {
        $this->assertSame(ComplianceStatus::Conforme, $this->compliance($this->driverWith(license: null, taxi: null)));
    }

    public function test_validite_des_documents_aux_bornes(): void
    {
        $today = DocumentValidity::today();

        $this->assertSame(DocumentValidity::Valide, DocumentValidity::fromExpiry(null));
        $this->assertSame(DocumentValidity::Valide, DocumentValidity::fromExpiry($today->copy()->addDays(31)));
        $this->assertSame(DocumentValidity::ExpireBientot, DocumentValidity::fromExpiry($today->copy()->addDays(30)));
        $this->assertSame(DocumentValidity::ExpireBientot, DocumentValidity::fromExpiry($today->copy()));
        $this->assertSame(DocumentValidity::Expire, DocumentValidity::fromExpiry($today->copy()->subDay()));
    }

    public function test_permis_manquant_non_conforme(): void
    {
        $driver = $this->pendingDriver();
        $this->addDocument($driver, DocumentType::TaxiLicense);
        $moto = $this->motorcycle();
        $this->addDocument($moto, DocumentType::Insurance);
        $this->assign($driver, $moto);

        $this->assertSame(ComplianceStatus::NonConforme, $this->compliance($driver->fresh()));
    }

    public function test_sans_moto_l_assurance_manque_non_conforme(): void
    {
        $driver = $this->pendingDriver();
        $this->addDocument($driver, DocumentType::DrivingLicense);
        $this->addDocument($driver, DocumentType::TaxiLicense);

        $this->assertSame(ComplianceStatus::NonConforme, $this->compliance($driver->fresh()));
    }

    public function test_la_piece_d_identite_n_entre_pas_dans_la_conformite(): void
    {
        $driver = $this->driverWith();
        $this->addDocument($driver, DocumentType::IdCard, -100); // pièce d'identité expirée

        $this->assertSame(ComplianceStatus::Conforme, $this->compliance($driver->fresh()));
    }

    public function test_moto_changee_l_assurance_de_la_nouvelle_moto_compte(): void
    {
        $driver = $this->driverWith(); // première moto : assurance valide
        $this->assertSame(ComplianceStatus::Conforme, $driver->compliance_status);

        $expired = $this->motorcycle();
        $this->addDocument($expired, DocumentType::Insurance, -10);
        $this->assign($driver, $expired, 'Changement de moto');

        $this->assertSame(ComplianceStatus::NonConforme, $driver->fresh()->compliance_status);

        $soon = $this->motorcycle();
        $this->addDocument($soon, DocumentType::Insurance, 12);
        $this->assign($driver, $soon, 'Nouveau changement');

        $this->assertSame(ComplianceStatus::ExpireBientot, $driver->fresh()->compliance_status);
    }

    public function test_renouveler_l_assurance_de_la_moto_recalcule_son_conducteur(): void
    {
        $driver = $this->driverWith(insurance: -5);
        $this->assertSame(ComplianceStatus::NonConforme, $driver->compliance_status);

        $this->addDocument($driver->currentAssignment->motorcycle, DocumentType::Insurance, 300);

        $this->assertSame(ComplianceStatus::Conforme, $driver->fresh()->compliance_status);
    }

    public function test_la_conformite_ne_change_jamais_le_statut(): void
    {
        $driver = $this->activeDriver();
        $this->addDocument($driver, DocumentType::DrivingLicense, -3);

        $driver->refresh();
        $this->assertSame(ComplianceStatus::NonConforme, $driver->compliance_status);
        $this->assertSame(DriverStatus::Actif, $driver->status);
    }

    public function test_recalcul_nocturne_suit_le_passage_du_temps(): void
    {
        $driver = $this->driverWith(license: 31);
        $this->assertSame(ComplianceStatus::Conforme, $driver->compliance_status);

        $this->travel(2)->days();
        $this->artisan('zemiconnect:recalculate-compliance')->assertSuccessful();
        $this->assertSame(ComplianceStatus::ExpireBientot, $driver->fresh()->compliance_status);

        $this->travel(40)->days();
        $this->artisan('zemiconnect:recalculate-compliance')->assertSuccessful();
        $this->assertSame(ComplianceStatus::NonConforme, $driver->fresh()->compliance_status);
    }

    public function test_la_commande_est_planifiee_chaque_nuit(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('zemiconnect:recalculate-compliance')->assertSuccessful();
    }
}
