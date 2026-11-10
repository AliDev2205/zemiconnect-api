<?php

namespace Database\Seeders;

use App\Enums\ComplianceStatus;
use App\Enums\DocumentType;
use App\Enums\DriverStatus;
use App\Enums\QrStatus;
use App\Enums\UserStatus;
use App\Models\AgentProfile;
use App\Models\Assignment;
use App\Models\Document;
use App\Models\Driver;
use App\Models\Motorcycle;
use App\Models\ProfessionalConsultation;
use App\Models\QrCode;
use App\Models\Setting;
use App\Models\User;
use App\Services\ZemiNumberGenerator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Données de démonstration, en LOCAL uniquement : mêmes comptes et mêmes cas
 * que les données fictives du frontend (zemiconnect-web/src/mocks).
 * Dates relatives au jour du seed, pour que « expire bientôt » reste vrai.
 *
 * Adaptations imposées par la base (voir README) :
 * - numéros ZemiConnect : les 6 chiffres des maquettes + clé Luhn (ZC-482917 -> ZC-4829172) ;
 * - immatriculations stockées normalisées (BB 4521 RB -> BB4521RB) ;
 * - téléphone obligatoire : numéro fictif pour les conducteurs qui n'en avaient pas ;
 * - assurance de la moto BB5590RB : date d'expiration obligatoire (dans 300 jours).
 */
class DemoDataSeeder extends Seeder
{
    public const PASSWORD = 'demo-zc-2026';

    public const DOMAIN = 'zemiconnect.exemple';

    private Carbon $today;

    /** @var array<string, User> */
    private array $users = [];

    /** @var array<string, Motorcycle> */
    private array $motorcycles = [];

    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('DemoDataSeeder ne s\'exécute qu\'en environnement local.');
        }

        $this->today = Carbon::today('UTC');

        Model::unguarded(function (): void {
            $this->seedUsers();
            $this->seedMotorcycles();
            $this->seedDrivers();
            $this->seedSettings();
        });
    }

    private function seedUsers(): void
    {
        $accounts = [
            'admin' => ['Kofi', 'DOSSOU', 'kofi.dossou', '+229 01 97 00 00 01', 'admin', UserStatus::Active, false, '2026-11-04 08:12:00', '2026-01-10 09:00:00', null],
            'gest' => ['Aïcha', 'TOKO', 'aicha.toko', '+229 01 97 00 00 02', 'gestionnaire', UserStatus::Active, false, '2026-11-04 10:30:00', '2026-02-01 09:00:00', null],
            'gest2' => ['Sylvain', 'HOUNKPATIN', 'sylvain.hounkpatin', null, 'gestionnaire', UserStatus::Inactive, false, '2026-09-12 15:00:00', '2026-02-15 09:00:00', null],
            'agent1' => ['Bachirou', 'SANNI', 'bachirou.sanni', '+229 01 97 00 00 03', 'agent', UserStatus::Active, false, '2026-11-05 07:45:00', '2026-03-01 09:00:00', ['AG-0142', 'Police municipale de Parakou (fictive)']],
            'agent2' => ['Florent', 'AGBO', 'florent.agbo', '+229 01 97 00 00 04', 'agent', UserStatus::Active, true, null, '2026-10-20 09:00:00', ['AG-0157', 'Police municipale de Parakou (fictive)']],
            'agent3' => ['Chantal', 'SEIDOU', 'chantal.seidou', '+229 01 97 00 00 05', 'agent', UserStatus::Inactive, false, '2026-08-14 16:20:00', '2026-03-12 09:00:00', ['AG-0127', 'Syndicat des conducteurs de taxi-moto (fictif)']],
            'agent4' => ['Ousmane', 'ADAM', 'ousmane.adam', null, 'agent', UserStatus::Archived, false, '2026-05-02 10:05:00', '2026-03-15 09:00:00', ['AG-0118', 'Police municipale de Parakou (fictive)']],
        ];

        foreach ($accounts as $key => [$first, $last, $login, $phone, $role, $status, $mustChange, $lastLogin, $createdAt, $agent]) {
            $creator = match ($role) {
                'admin' => null,
                'gestionnaire' => $this->users['admin'] ?? null,
                default => $this->users['gest'] ?? null,
            };

            $user = User::query()->updateOrCreate(['email' => "{$login}@".self::DOMAIN], [
                'first_name' => $first,
                'last_name' => $last,
                'phone' => $phone,
                'password' => self::PASSWORD,
                'status' => $status,
                'must_change_password' => $mustChange,
                'last_login_at' => $lastLogin,
                'archived_at' => $status === UserStatus::Archived ? '2026-05-10 09:00:00' : null,
                'created_by' => $creator?->id,
                'created_at' => $createdAt,
            ]);
            $user->syncRoles([$role]);

            if ($agent !== null) {
                AgentProfile::query()->updateOrCreate(['user_id' => $user->id], ['matricule' => $agent[0], 'organization' => $agent[1]]);
            }

            $this->users[$key] = $user;
        }
    }

    private function seedMotorcycles(): void
    {
        // [immatriculation, marque, modèle, couleur, année, châssis, expiration de l'assurance (jours) ou null = aucune]
        $rows = [
            'mot-1' => ['BB 4521 RB', 'Bajaj', 'Boxer BM 150', 'Rouge', 2021, 'MD2A21AY5MW894102', 70],
            'mot-2' => ['BB 7310 RB', 'Haojue', 'HJ 125', 'Noir', 2019, 'LC6PCJK69K0012345', 7],
            'mot-3' => ['BB 1188 RB', 'TVS', 'Star HLX', 'Bleu', 2018, null, -66],
            'mot-4' => ['BB 9042 RB', 'Bajaj', 'Boxer BM 100', 'Rouge', null, null, null],
            'mot-5' => ['BB 3307 RB', 'Haojue', 'HJ 110', 'Bleu', 2022, 'LC6PCJB12N0098761', 236],
            'mot-6' => ['BB 5590 RB', 'Bajaj', 'Boxer BM 125', 'Vert', 2020, 'MD2A18AZ3LW551230', 300],
            'mot-7' => ['BB 6618 RB', 'TVS', 'HLX 125', 'Noir', 2023, 'TVS2023-1187', 150],
            'mot-8' => ['BB 7710 RB', 'TVS', 'HLX 125', 'Rouge', 2023, 'TVS2023-8841', 12],
            'mot-9' => ['BB 1234 RB', 'Haojue', 'HJ 125', 'Bleu', 2021, null, 200],
        ];

        foreach ($rows as $key => [$plate, $brand, $model, $color, $year, $chassis, $insuranceDays]) {
            $moto = Motorcycle::query()->updateOrCreate(['plate_number' => Motorcycle::normalizePlate($plate)], [
                'brand' => $brand,
                'model' => $model,
                'color' => $color,
                'manufacture_year' => $year,
                'chassis_number' => $chassis,
                'created_by' => $this->users['gest']->id,
                'created_at' => $this->at(300),
            ]);

            if ($insuranceDays !== null) {
                $this->document(DocumentType::Insurance, null, $moto, $insuranceDays, "POL-2024-{$moto->id}0417", category: 'Responsabilité civile', issuer: 'Assureur fictif SA');
            }

            $this->motorcycles[$key] = $moto;
        }
    }

    private function seedDrivers(): void
    {
        $generator = app(ZemiNumberGenerator::class);
        $zc = fn (string $six) => config('zemiconnect.zemi_number.prefix').$six.$generator::luhnCheckDigit($six);

        // Documents : [type => jours avant expiration, null = sans date]
        $drivers = [
            'drv-1' => [
                'zemi' => '482917', 'first' => 'Moussa', 'last' => 'ALASSANE', 'phone' => '+229 01 96 12 34 56', 'address' => 'Quartier Titirou, Parakou',
                'birth' => '1984-05-14', 'ident' => 'CIP-229-48190', 'photo' => 'moussa-alassane.jpg', 'status' => DriverStatus::Actif, 'moto' => 'mot-1', 'days' => 240,
                'docs' => ['id_card' => 1660, 'driving_license' => null, 'taxi_license' => 146, 'registration_form' => null],
                'qr' => [['demo-moussa', 239, null, null], ['demo-revoque', 240, 239, 'Badge réimprimé après une erreur de photo.']],
            ],
            'drv-2' => [
                'zemi' => '305611', 'first' => 'Ibrahim', 'last' => 'SALIFOU', 'phone' => '+229 01 95 22 11 08', 'address' => 'Quartier Banikanni, Parakou',
                'birth' => '1990-02-03', 'status' => DriverStatus::Actif, 'moto' => 'mot-2', 'days' => 220,
                'docs' => ['id_card' => 800, 'driving_license' => 1240, 'taxi_license' => 115],
                'qr' => [['demo-ibrahim', 220, null, null]],
            ],
            'drv-3' => [
                'zemi' => '771204', 'first' => 'Rachidi', 'last' => 'BIO', 'phone' => '+229 01 90 00 00 13', 'status' => DriverStatus::Suspendu,
                'reason' => 'Plainte d\'un passager en cours d\'examen (fictif).', 'moto' => 'mot-3', 'days' => 210,
                'docs' => ['id_card' => 600, 'taxi_license' => -128],
                'qr' => [['demo-rachidi', 210, null, null]],
            ],
            'drv-4' => [
                'first' => 'Saka', 'last' => 'GOUNOU', 'phone' => '+229 01 94 40 50 60', 'address' => 'Quartier Guema, Parakou', 'birth' => '1997-07-30',
                'status' => DriverStatus::EnAttente, 'days' => 8, 'docs' => ['id_card' => 1200],
            ],
            'drv-5' => [
                'zemi' => '664430', 'first' => 'Issa', 'last' => 'YAROU', 'phone' => '+229 01 90 00 00 15', 'status' => DriverStatus::Archive,
                'reason' => 'Cessation d\'activité (fictif).', 'archived' => 120, 'days' => 230,
                'docs' => ['id_card' => 300, 'driving_license' => 900],
                'qr' => [['demo-archive', 230, 120, 'Dossier archivé']],
            ],
            'drv-6' => [
                'zemi' => '559013', 'first' => 'Kassim', 'last' => 'ORÉ', 'phone' => '+229 01 97 31 20 44', 'address' => 'Quartier Zongo, Parakou', 'birth' => '1995-09-21',
                'status' => DriverStatus::Actif, 'moto' => 'mot-5', 'days' => 180,
                'docs' => ['id_card' => 1900, 'driving_license' => 1030],
                'qr' => [['demo-kassim', 180, null, null]],
            ],
            'drv-7' => [
                'zemi' => '840266', 'first' => 'Gildas', 'last' => 'KOUAGOU', 'phone' => '+229 01 93 66 18 02', 'birth' => '1988-12-02',
                'status' => DriverStatus::Actif, 'days' => 160,
                'docs' => ['id_card' => 1330, 'driving_license' => 1575, 'taxi_license' => 85],
                'qr' => [['demo-sans-moto', 160, null, null]],
            ],
            'drv-8' => [
                'zemi' => '217745', 'first' => 'Yacoubou', 'last' => 'BANI', 'phone' => '+229 01 92 05 77 31', 'address' => 'Quartier Albarika, Parakou',
                'birth' => '1979-03-08', 'ident' => 'REG-PKU-0217', 'status' => DriverStatus::Actif, 'moto' => 'mot-6', 'days' => 150,
                'docs' => ['id_card' => null, 'driving_license' => null, 'taxi_license' => null],
                'qr' => [['demo-sans-date', 150, null, null]],
            ],
            'drv-9' => [
                'first' => 'Fousséni', 'last' => 'TANKO', 'phone' => '+229 01 97 02 14 78', 'address' => 'Quartier Dépôt, Parakou', 'birth' => '1992-11-19',
                'photo' => 'photo-fictive.png', 'status' => DriverStatus::EnAttente, 'moto' => 'mot-7', 'days' => 3,
                'docs' => ['id_card' => 2100, 'driving_license' => 1400, 'taxi_license' => 320],
            ],
        ];

        $manager = $this->users['gest'];

        foreach ($drivers as $key => $row) {
            $validated = $row['status'] !== DriverStatus::EnAttente;
            $driver = Driver::query()->updateOrCreate(['phone' => $row['phone'], 'last_name' => $row['last']], [
                'zemi_number' => isset($row['zemi']) ? $zc($row['zemi']) : null,
                'first_name' => $row['first'],
                'birth_date' => $row['birth'] ?? null,
                'identification_number' => $row['ident'] ?? null,
                'address' => $row['address'] ?? null,
                'photo_path' => isset($row['photo']) ? $this->photo($row['photo']) : null,
                'status' => $row['status'],
                'status_reason' => $row['reason'] ?? null,
                'validated_at' => $validated ? $this->at($row['days']) : null,
                'validated_by' => $validated ? $manager->id : null,
                'archived_at' => isset($row['archived']) ? $this->at($row['archived']) : null,
                'created_by' => $manager->id,
                'created_at' => $this->at($row['days'] + 2),
                'updated_at' => $this->at(20),
            ]);

            foreach ($row['docs'] as $type => $days) {
                $this->document(DocumentType::from($type), $driver, null, $days, strtoupper(substr($type, 0, 2))."-2024-{$driver->id}{$type[0]}");
            }

            $moto = isset($row['moto']) ? $this->motorcycles[$row['moto']] : null;
            if ($moto !== null) {
                Assignment::query()->firstOrCreate(['driver_id' => $driver->id, 'motorcycle_id' => $moto->id], [
                    'started_at' => $this->today->copy()->subDays($row['days']),
                    'start_reason' => 'Première affectation',
                    'created_by' => $manager->id,
                ]);
            }

            foreach ($row['qr'] ?? [] as [$token, $issuedDays, $revokedDays, $revokeReason]) {
                QrCode::query()->firstOrCreate(['token' => $token], [
                    'driver_id' => $driver->id,
                    'status' => $revokedDays === null ? QrStatus::Active : QrStatus::Revoked,
                    'generated_by' => $manager->id,
                    'revoked_at' => $revokedDays === null ? null : $this->at($revokedDays),
                    'revoked_by' => $revokedDays === null ? null : $manager->id,
                    'revoke_reason' => $revokeReason,
                    'created_at' => $this->at($issuedDays),
                ]);
            }

            $driver->forceFill([
                'compliance_status' => $this->compliance($row['docs'], $moto),
                'compliance_checked_at' => now(),
            ])->save();

            $drivers[$key]['model'] = $driver;
        }

        $moussa = $drivers['drv-1']['model'];

        // Historique de Moussa : affectation passée clôturée et droit de taxi remplacé.
        Assignment::query()->firstOrCreate(['driver_id' => $moussa->id, 'motorcycle_id' => $this->motorcycles['mot-4']->id], [
            'started_at' => $this->today->copy()->subDays(420),
            'ended_at' => $this->today->copy()->subDays(240),
            'start_reason' => 'Première affectation',
            'end_reason' => 'Changement de moto',
            'created_by' => $manager->id,
            'ended_by' => $manager->id,
        ]);

        Document::query()->firstOrCreate(['driver_id' => $moussa->id, 'number' => 'DT-PKU-2023-000412'], [
            'type' => DocumentType::TaxiLicense,
            'issued_at' => $this->today->copy()->subDays(584),
            'expires_at' => $this->today->copy()->subDays(219),
            'is_current' => false,
            'created_by' => $manager->id,
            'created_at' => $this->at(584),
            'updated_at' => $this->at(220),
        ]);

        // Quelques consultations d'agent (Bachirou SANNI).
        $agent = $this->users['agent1'];
        $consultations = [
            ['drv-1', 'qr', 'demo-moussa', 2],
            ['drv-2', 'recherche', null, 1],
            ['drv-3', 'qr', 'demo-rachidi', 0],
        ];
        foreach ($consultations as [$driverKey, $channel, $token, $daysAgo]) {
            ProfessionalConsultation::query()->create([
                'agent_id' => $agent->id,
                'driver_id' => $drivers[$driverKey]['model']->id,
                'qr_code_id' => $token ? QrCode::query()->where('token', $token)->value('id') : null,
                'channel' => $channel,
                'ip_address' => '102.64.10.21',
                'user_agent' => 'Mozilla/5.0 (Linux; Android 13) Démonstration',
                'created_at' => $this->at($daysAgo, 8),
            ]);
        }
    }

    private function seedSettings(): void
    {
        $admin = $this->users['admin'];
        Setting::query()->updateOrCreate(['key' => 'structure.name'], ['value' => 'Coopérative des zémidjans de Parakou-Centre (fictive)', 'updated_by' => $admin->id]);
        Setting::query()->updateOrCreate(['key' => 'structure.contact'], ['value' => '+229 01 90 00 00 00', 'updated_by' => $admin->id]);
    }

    private function document(DocumentType $type, ?Driver $driver, ?Motorcycle $moto, ?int $expiresInDays, string $number, ?string $category = null, ?string $issuer = null): Document
    {
        return Document::query()->firstOrCreate([
            'driver_id' => $driver?->id,
            'motorcycle_id' => $moto?->id,
            'type' => $type,
            'is_current' => true,
        ], [
            'number' => $number,
            'category' => $category,
            'issuer' => $issuer,
            'issued_at' => $this->today->copy()->subDays(600),
            'expires_at' => $expiresInDays === null ? null : $this->today->copy()->addDays($expiresInDays),
            'created_by' => $this->users['gest']->id,
            'created_at' => $this->at(240),
        ]);
    }

    /**
     * Conformité (RG06), calculée ici pour la démonstration seulement : le
     * ComplianceService arrive dans un lot suivant. Permis, droit de taxi et
     * assurance de la moto actuelle ; sans date d'expiration = valide (B3).
     *
     * @param  array<string, int|null>  $docs
     */
    private function compliance(array $docs, ?Motorcycle $moto): ComplianceStatus
    {
        $warning = (int) config('zemiconnect.compliance.expiry_warning_days');
        $insurance = $moto?->currentInsurance()->first();
        $insuranceDays = $insurance === null ? false : (int) $this->today->diffInDays($insurance->expires_at, false);

        $checked = [
            array_key_exists('driving_license', $docs) ? $docs['driving_license'] : false,
            array_key_exists('taxi_license', $docs) ? $docs['taxi_license'] : false,
            $insuranceDays,
        ];

        $status = ComplianceStatus::Conforme;
        foreach ($checked as $days) {
            if ($days === false || ($days !== null && $days < 0)) {
                return ComplianceStatus::NonConforme;
            }
            if ($days !== null && $days <= $warning) {
                $status = ComplianceStatus::ExpireBientot;
            }
        }

        return $status;
    }

    /**
     * Copie la photo fictive du frontend sur le disque privé si elle est disponible.
     */
    private function photo(string $file): string
    {
        $path = "drivers/photos/{$file}";
        $source = base_path("../zemiconnect-web/public/mocks/{$file}");

        if (File::exists($source) && ! Storage::disk('local')->exists($path)) {
            Storage::disk('local')->put($path, File::get($source));
        }

        return $path;
    }

    private function at(int $daysAgo, int $hour = 9): Carbon
    {
        return $this->today->copy()->subDays($daysAgo)->setTime($hour, 0);
    }
}
