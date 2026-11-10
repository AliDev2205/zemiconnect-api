<?php

namespace App\Console\Commands;

use App\Services\ComplianceService;
use Illuminate\Console\Command;

/**
 * Recalcul nocturne de la conformité (passage du temps : un document qui
 * arrive à J-30 ou expire change la conformité sans aucune écriture).
 * Planifiée chaque nuit dans routes/console.php.
 */
class RecalculateCompliance extends Command
{
    protected $signature = 'zemiconnect:recalculate-compliance';

    protected $description = 'Recalcule la conformité documentaire de tous les dossiers conducteurs';

    public function handle(ComplianceService $compliance): int
    {
        $result = $compliance->recalculateAll();

        $this->info("Conformité recalculée : {$result['checked']} dossier(s), {$result['changed']} changement(s).");

        return self::SUCCESS;
    }
}
