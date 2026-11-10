<?php

namespace Tests\Feature\Database;

use App\Models\Driver;
use App\Models\Motorcycle;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Contraintes des parties 4 et 5 de la conception BDD : chaque règle est
 * vérifiée par une écriture que MySQL doit REFUSER (requêtes brutes, sans
 * passer par l'application), plus le cas autorisé correspondant.
 */
class ConstraintsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    // ── Outils ───────────────────────────────────────────────────────────

    /** Codes d'erreur MySQL (indépendants de la langue des messages du serveur). */
    private const FK_PARENT_ROW = 1451;

    private const DATA_TRUNCATED = 1265;

    /**
     * @param  string|int  $expected  nom de contrainte / texte attendu, ou code d'erreur MySQL
     */
    private function assertRejected(callable $write, string|int $expected): void
    {
        try {
            $write();
        } catch (QueryException $e) {
            is_int($expected)
                ? $this->assertSame($expected, $e->errorInfo[1] ?? null, $e->getMessage())
                : $this->assertStringContainsString($expected, $e->getMessage());

            return;
        }

        $this->fail("La base aurait dû refuser cette écriture ({$expected}).");
    }

    private function driver(array $attributes = []): Driver
    {
        return Driver::factory()->create(['created_by' => $this->user->id] + $attributes);
    }

    private function activeDriver(): Driver
    {
        return Driver::factory()->active()->create(['created_by' => $this->user->id]);
    }

    private function moto(array $attributes = []): Motorcycle
    {
        return Motorcycle::factory()->create(['created_by' => $this->user->id] + $attributes);
    }

    private function stamps(): array
    {
        return ['created_at' => now(), 'updated_at' => now()];
    }

    private function insertAssignment(int $driverId, int $motoId, array $attributes = []): void
    {
        DB::table('driver_motorcycle_assignments')->insert(array_merge([
            'driver_id' => $driverId,
            'motorcycle_id' => $motoId,
            'started_at' => '2026-01-01',
            'created_by' => $this->user->id,
        ], $this->stamps(), $attributes));
    }

    private function insertDocument(array $attributes): void
    {
        DB::table('documents')->insert(array_merge([
            'type' => 'driving_license',
            'is_current' => 1,
            'created_by' => $this->user->id,
        ], $this->stamps(), $attributes));
    }

    private function insertQr(int $driverId, string $token, array $attributes = []): int
    {
        return DB::table('qr_codes')->insertGetId(array_merge([
            'driver_id' => $driverId,
            'token' => $token,
            'status' => 'active',
            'generated_by' => $this->user->id,
        ], $this->stamps(), $attributes));
    }

    // ── Moteur, types, conventions ───────────────────────────────────────

    public function test_toutes_les_tables_sont_en_innodb(): void
    {
        $others = DB::select("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND engine <> 'InnoDB'");

        $this->assertSame([], $others);
    }

    public function test_aucune_colonne_timestamp_les_dates_heures_sont_en_datetime(): void
    {
        $columns = DB::select("SELECT table_name, column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND data_type = 'timestamp'");

        $this->assertSame([], $columns);
    }

    public function test_les_cles_etrangeres_des_tables_metier_sont_en_restrict(): void
    {
        $notRestrict = DB::select(
            "SELECT table_name, constraint_name, delete_rule, update_rule FROM information_schema.referential_constraints
             WHERE constraint_schema = DATABASE()
               AND table_name NOT IN ('model_has_roles', 'model_has_permissions', 'role_has_permissions')
               AND (delete_rule <> 'RESTRICT' OR update_rule <> 'RESTRICT')"
        );

        $this->assertSame([], $notRestrict);
    }

    public function test_une_valeur_hors_enum_est_refusee(): void
    {
        $this->assertRejected(
            fn () => DB::table('drivers')->where('id', $this->driver()->id)->update(['status' => 'supprime']),
            self::DATA_TRUNCATED,
        );
    }

    // ── users ────────────────────────────────────────────────────────────

    public function test_email_de_compte_unique(): void
    {
        $this->assertRejected(fn () => User::factory()->create(['email' => $this->user->email]), 'users_email_unique');
    }

    public function test_compte_archive_sans_date_d_archivage_refuse(): void
    {
        $this->assertRejected(
            fn () => DB::table('users')->where('id', $this->user->id)->update(['status' => 'archived', 'archived_at' => null]),
            'users_archived_has_date',
        );
    }

    // ── agent_profiles ───────────────────────────────────────────────────

    public function test_un_seul_profil_agent_par_compte(): void
    {
        DB::table('agent_profiles')->insert(['user_id' => $this->user->id, 'matricule' => 'AG-1'] + $this->stamps());

        $this->assertRejected(
            fn () => DB::table('agent_profiles')->insert(['user_id' => $this->user->id, 'matricule' => 'AG-2'] + $this->stamps()),
            'agent_profiles_user_id_unique',
        );
    }

    public function test_matricule_unique(): void
    {
        $other = User::factory()->create();
        DB::table('agent_profiles')->insert(['user_id' => $this->user->id, 'matricule' => 'AG-1'] + $this->stamps());

        $this->assertRejected(
            fn () => DB::table('agent_profiles')->insert(['user_id' => $other->id, 'matricule' => 'AG-1'] + $this->stamps()),
            'agent_profiles_matricule_unique',
        );
    }

    // ── drivers : statut, numéro, déclencheur ────────────────────────────

    public function test_dossier_en_attente_avec_numero_refuse(): void
    {
        $this->assertRejected(fn () => $this->driver(['zemi_number' => 'ZC-4829172']), 'drivers_pending_without_number');
    }

    public function test_dossier_valide_sans_numero_refuse(): void
    {
        $this->assertRejected(
            fn () => $this->driver(['status' => 'actif', 'validated_at' => now()]),
            'drivers_pending_without_number',
        );
    }

    public function test_dossier_valide_sans_date_de_validation_refuse(): void
    {
        $this->assertRejected(
            fn () => $this->driver(['status' => 'actif', 'zemi_number' => 'ZC-4829172']),
            'drivers_validated_has_date',
        );
    }

    public function test_dossier_archive_sans_date_d_archivage_refuse(): void
    {
        $driver = $this->activeDriver();

        $this->assertRejected(
            fn () => DB::table('drivers')->where('id', $driver->id)->update(['status' => 'archive']),
            'drivers_archived_has_date',
        );
    }

    public function test_numero_zemiconnect_unique(): void
    {
        $driver = $this->activeDriver();

        $this->assertRejected(
            fn () => $this->driver(['status' => 'actif', 'validated_at' => now(), 'zemi_number' => $driver->zemi_number]),
            'drivers_zemi_number_unique',
        );
    }

    public function test_attribution_du_numero_a_la_validation_autorisee(): void
    {
        $driver = $this->driver();

        DB::table('drivers')->where('id', $driver->id)->update([
            'status' => 'actif', 'zemi_number' => 'ZC-4829172', 'validated_at' => now(),
        ]);

        $this->assertSame('ZC-4829172', $driver->fresh()->zemi_number);
    }

    public function test_modification_du_numero_attribue_refusee_par_le_declencheur(): void
    {
        $driver = $this->activeDriver();

        $this->assertRejected(
            fn () => DB::table('drivers')->where('id', $driver->id)->update(['zemi_number' => 'ZC-1111111']),
            'zemi_number ne peut pas etre modifie',
        );
    }

    public function test_effacement_du_numero_attribue_refuse_par_le_declencheur(): void
    {
        $driver = $this->activeDriver();

        $this->assertRejected(
            fn () => DB::table('drivers')->where('id', $driver->id)->update(['zemi_number' => null, 'status' => 'en_attente']),
            'zemi_number ne peut pas etre modifie',
        );
    }

    public function test_un_dossier_archive_garde_son_numero(): void
    {
        $driver = $this->activeDriver();

        DB::table('drivers')->where('id', $driver->id)->update(['status' => 'archive', 'archived_at' => now()]);

        $this->assertSame($driver->zemi_number, $driver->fresh()->zemi_number);
    }

    // ── motorcycles ──────────────────────────────────────────────────────

    public function test_immatriculation_unique(): void
    {
        $this->moto(['plate_number' => 'BB4521RB']);

        $this->assertRejected(fn () => $this->moto(['plate_number' => 'BB4521RB']), 'motorcycles_plate_number_unique');
    }

    public function test_chassis_unique_lorsqu_il_est_renseigne(): void
    {
        $this->moto(['chassis_number' => 'MD2A21AY5MW894102']);

        $this->assertRejected(fn () => $this->moto(['chassis_number' => 'MD2A21AY5MW894102']), 'motorcycles_chassis_number_unique');
    }

    public function test_plusieurs_motos_sans_chassis_autorisees(): void
    {
        $this->moto(['chassis_number' => null]);
        $this->moto(['chassis_number' => null]);

        $this->assertSame(2, Motorcycle::query()->whereNull('chassis_number')->count());
    }

    public function test_moto_archivee_sans_date_refusee(): void
    {
        $moto = $this->moto();

        $this->assertRejected(
            fn () => DB::table('motorcycles')->where('id', $moto->id)->update(['status' => 'archived']),
            'motorcycles_archived_has_date',
        );
    }

    // ── driver_motorcycle_assignments (RG08) ─────────────────────────────

    public function test_deuxieme_affectation_ouverte_pour_un_conducteur_refusee(): void
    {
        $driver = $this->driver();
        $this->insertAssignment($driver->id, $this->moto()->id);

        $this->assertRejected(
            fn () => $this->insertAssignment($driver->id, $this->moto()->id),
            'open_driver_id_unique',
        );
    }

    public function test_deuxieme_affectation_ouverte_pour_une_moto_refusee(): void
    {
        $moto = $this->moto();
        $this->insertAssignment($this->driver()->id, $moto->id);

        $this->assertRejected(
            fn () => $this->insertAssignment($this->driver()->id, $moto->id),
            'open_motorcycle_id_unique',
        );
    }

    public function test_affectations_cloturees_multiples_autorisees(): void
    {
        $driver = $this->driver();
        $moto = $this->moto();

        $this->insertAssignment($driver->id, $moto->id, ['started_at' => '2025-01-01', 'ended_at' => '2025-06-01']);
        $this->insertAssignment($driver->id, $moto->id, ['started_at' => '2025-06-01', 'ended_at' => '2025-12-01']);
        $this->insertAssignment($driver->id, $moto->id, ['started_at' => '2025-12-01']);

        $this->assertSame(3, DB::table('driver_motorcycle_assignments')->where('driver_id', $driver->id)->count());
    }

    public function test_fin_d_affectation_avant_le_debut_refusee(): void
    {
        $this->assertRejected(
            fn () => $this->insertAssignment($this->driver()->id, $this->moto()->id, ['started_at' => '2026-02-01', 'ended_at' => '2026-01-31']),
            'assignments_end_after_start',
        );
    }

    // ── documents (B2, B3, RG07, RG11) ───────────────────────────────────

    public function test_assurance_rattachee_a_un_conducteur_refusee(): void
    {
        $this->assertRejected(
            fn () => $this->insertDocument(['type' => 'insurance', 'driver_id' => $this->driver()->id, 'expires_at' => '2027-01-01']),
            'documents_single_owner',
        );
    }

    public function test_permis_rattache_a_une_moto_refuse(): void
    {
        $this->assertRejected(
            fn () => $this->insertDocument(['type' => 'driving_license', 'motorcycle_id' => $this->moto()->id]),
            'documents_single_owner',
        );
    }

    public function test_document_avec_deux_proprietaires_refuse(): void
    {
        $this->assertRejected(
            fn () => $this->insertDocument(['type' => 'id_card', 'driver_id' => $this->driver()->id, 'motorcycle_id' => $this->moto()->id]),
            'documents_single_owner',
        );
    }

    public function test_document_sans_proprietaire_refuse(): void
    {
        $this->assertRejected(fn () => $this->insertDocument(['type' => 'id_card']), 'documents_single_owner');
    }

    public function test_assurance_sans_date_d_expiration_refusee(): void
    {
        $this->assertRejected(
            fn () => $this->insertDocument(['type' => 'insurance', 'motorcycle_id' => $this->moto()->id, 'expires_at' => null]),
            'documents_insurance_has_expiry',
        );
    }

    public function test_permis_et_droit_de_taxi_sans_date_d_expiration_autorises(): void
    {
        $driver = $this->driver();

        $this->insertDocument(['type' => 'driving_license', 'driver_id' => $driver->id, 'expires_at' => null]);
        $this->insertDocument(['type' => 'taxi_license', 'driver_id' => $driver->id, 'expires_at' => null]);

        $this->assertSame(2, DB::table('documents')->where('driver_id', $driver->id)->count());
    }

    public function test_expiration_non_posterieure_a_la_delivrance_refusee(): void
    {
        $this->assertRejected(
            fn () => $this->insertDocument(['driver_id' => $this->driver()->id, 'issued_at' => '2026-05-01', 'expires_at' => '2026-05-01']),
            'documents_expiry_after_issue',
        );
    }

    public function test_deux_documents_actuels_du_meme_type_pour_un_conducteur_refuses(): void
    {
        $driver = $this->driver();
        $this->insertDocument(['type' => 'driving_license', 'driver_id' => $driver->id]);

        $this->assertRejected(
            fn () => $this->insertDocument(['type' => 'driving_license', 'driver_id' => $driver->id]),
            'documents_current_driver_id_type_unique',
        );
    }

    public function test_deux_assurances_actuelles_pour_une_moto_refusees(): void
    {
        $moto = $this->moto();
        $this->insertDocument(['type' => 'insurance', 'motorcycle_id' => $moto->id, 'expires_at' => '2027-01-01']);

        $this->assertRejected(
            fn () => $this->insertDocument(['type' => 'insurance', 'motorcycle_id' => $moto->id, 'expires_at' => '2028-01-01']),
            'documents_current_motorcycle_id_type_unique',
        );
    }

    public function test_renouvellement_ancien_document_en_historique_autorise(): void
    {
        $driver = $this->driver();
        $this->insertDocument(['type' => 'driving_license', 'driver_id' => $driver->id, 'is_current' => 0]);
        $this->insertDocument(['type' => 'driving_license', 'driver_id' => $driver->id, 'is_current' => 0]);
        $this->insertDocument(['type' => 'driving_license', 'driver_id' => $driver->id, 'is_current' => 1]);
        $this->insertDocument(['type' => 'taxi_license', 'driver_id' => $driver->id, 'is_current' => 1]);

        $this->assertSame(4, DB::table('documents')->where('driver_id', $driver->id)->count());
    }

    // ── qr_codes (RG12) ──────────────────────────────────────────────────

    public function test_deuxieme_qr_actif_pour_un_conducteur_refuse(): void
    {
        $driver = $this->activeDriver();
        $this->insertQr($driver->id, 'Ab12Cd34Ef56Gh78');

        $this->assertRejected(fn () => $this->insertQr($driver->id, 'Zz98Yy76Xx54Ww32'), 'qr_codes_active_driver_id_unique');
    }

    public function test_qr_revoques_multiples_et_un_actif_autorises(): void
    {
        $driver = $this->activeDriver();
        $this->insertQr($driver->id, 'old-1', ['status' => 'revoked', 'revoked_at' => now()]);
        $this->insertQr($driver->id, 'old-2', ['status' => 'revoked', 'revoked_at' => now()]);
        $this->insertQr($driver->id, 'new-1');

        $this->assertSame(3, DB::table('qr_codes')->where('driver_id', $driver->id)->count());
    }

    public function test_jeton_jamais_reutilise_meme_revoque(): void
    {
        $this->insertQr($this->activeDriver()->id, 'Ab12Cd34Ef56Gh78', ['status' => 'revoked', 'revoked_at' => now()]);

        $this->assertRejected(fn () => $this->insertQr($this->activeDriver()->id, 'Ab12Cd34Ef56Gh78'), 'qr_codes_token_unique');
    }

    public function test_jeton_sensible_a_la_casse(): void
    {
        $this->insertQr($this->activeDriver()->id, 'abcdefgh12345678');
        $this->insertQr($this->activeDriver()->id, 'ABCDEFGH12345678');

        $this->assertSame(2, DB::table('qr_codes')->count());
    }

    public function test_qr_revoque_sans_date_de_revocation_refuse(): void
    {
        $this->assertRejected(
            fn () => $this->insertQr($this->activeDriver()->id, 'tok-1', ['status' => 'revoked']),
            'qr_codes_revoked_has_date',
        );
    }

    public function test_qr_actif_avec_date_de_revocation_refuse(): void
    {
        $this->assertRejected(
            fn () => $this->insertQr($this->activeDriver()->id, 'tok-1', ['revoked_at' => now()]),
            'qr_codes_revoked_has_date',
        );
    }

    // ── professional_consultations ───────────────────────────────────────

    public function test_consultation_par_qr_sans_qr_refusee(): void
    {
        $this->assertRejected(
            fn () => DB::table('professional_consultations')->insert([
                'agent_id' => $this->user->id,
                'driver_id' => $this->activeDriver()->id,
                'channel' => 'qr',
                'created_at' => now(),
            ]),
            'consultations_qr_channel_has_qr',
        );
    }

    public function test_consultation_par_recherche_sans_qr_autorisee(): void
    {
        DB::table('professional_consultations')->insert([
            'agent_id' => $this->user->id,
            'driver_id' => $this->activeDriver()->id,
            'channel' => 'recherche',
            'created_at' => now(),
        ]);

        $this->assertSame(1, DB::table('professional_consultations')->count());
    }

    // ── settings ─────────────────────────────────────────────────────────

    public function test_cle_de_parametre_unique(): void
    {
        DB::table('settings')->insert(['key' => 'structure.name', 'value' => '"A"'] + $this->stamps());

        $this->assertRejected(
            fn () => DB::table('settings')->insert(['key' => 'structure.name', 'value' => '"B"'] + $this->stamps()),
            'settings_key_unique',
        );
    }

    // ── Aucune suppression physique (RESTRICT) ───────────────────────────

    public function test_suppression_d_un_conducteur_relie_refusee(): void
    {
        $driver = $this->driver();
        $this->insertDocument(['type' => 'id_card', 'driver_id' => $driver->id]);

        $this->assertRejected(fn () => DB::table('drivers')->where('id', $driver->id)->delete(), self::FK_PARENT_ROW);
    }

    public function test_suppression_d_une_moto_affectee_refusee(): void
    {
        $moto = $this->moto();
        $this->insertAssignment($this->driver()->id, $moto->id);

        $this->assertRejected(fn () => DB::table('motorcycles')->where('id', $moto->id)->delete(), self::FK_PARENT_ROW);
    }

    public function test_suppression_d_un_compte_auteur_refusee(): void
    {
        $this->driver();

        $this->assertRejected(fn () => DB::table('users')->where('id', $this->user->id)->delete(), self::FK_PARENT_ROW);
    }
}
