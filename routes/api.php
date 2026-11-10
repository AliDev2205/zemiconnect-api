<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| ZemiConnect - Routes API v1
|--------------------------------------------------------------------------
| Point d'entrée unique (convention HealthBook) : aucune route ici. Chaque
| domaine a son dossier routes/api/v1/<domaine>/ ; tous les fichiers PHP
| qu'il contient sont chargés sous le préfixe /api/v1.
|
| Domaines prévus : auth, public, agent, drivers, motorcycles, documents,
| qr, agents, dashboard, logs, administration. Lot 1 : auth seulement.
*/

Route::prefix('v1')->group(function () {
    foreach (glob(__DIR__.'/api/v1/*/*.php') ?: [] as $file) {
        require $file;
    }
});
