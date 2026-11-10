<?php

use App\Http\Controllers\Api\Documents\DocumentController;
use App\Http\Controllers\Api\Drivers\DriverController;
use App\Http\Controllers\Api\Drivers\DriverHistoryController;
use App\Http\Controllers\Api\Drivers\DriverStatusController;
use App\Http\Controllers\Api\Motorcycles\AssignmentController;
use App\Models\Document;
use App\Models\Driver;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Conducteurs (GE2, GE3, GE4)
|--------------------------------------------------------------------------
| Préfixe parent : /api/v1
| Autorisations : DriverPolicy / DocumentPolicy (middleware can:), donc avant
| toute validation : un administrateur reçoit 403 sur une écriture, quel que
| soit le corps envoyé. L'agent n'a accès à aucune de ces routes.
*/

Route::middleware(['auth:sanctum', 'active', 'password.changed'])->prefix('drivers')->name('drivers.')->group(function () {

    Route::get('/', [DriverController::class, 'index'])->can('viewAny', Driver::class)->name('index');
    Route::get('duplicates', [DriverController::class, 'duplicates'])->can('create', Driver::class)->name('duplicates');
    Route::post('/', [DriverController::class, 'store'])->can('create', Driver::class)->name('store');

    Route::get('{driver}', [DriverController::class, 'show'])->can('view', 'driver')->name('show');
    Route::put('{driver}', [DriverController::class, 'update'])->can('update', 'driver')->name('update');
    Route::post('{driver}/photo', [DriverController::class, 'updatePhoto'])->can('update', 'driver')->name('photo.update');

    // Transitions de statut
    Route::post('{driver}/validate', [DriverStatusController::class, 'validateDriver'])->can('update', 'driver')->name('validate');
    Route::patch('{driver}/status', [DriverStatusController::class, 'update'])->can('update', 'driver')->name('status');
    Route::post('{driver}/archive', [DriverStatusController::class, 'archive'])->can('update', 'driver')->name('archive');

    Route::get('{driver}/history', DriverHistoryController::class)->can('view', 'driver')->name('history');

    // Affectations
    Route::post('{driver}/assignment', [AssignmentController::class, 'store'])->can('assignMotorcycle', 'driver')->name('assignment.store');
    Route::get('{driver}/assignments', [AssignmentController::class, 'forDriver'])->can('viewAssignments', 'driver')->name('assignments');

    // Documents
    Route::post('{driver}/documents', [DocumentController::class, 'storeForDriver'])->can('create', Document::class)->name('documents.store');
    Route::get('{driver}/documents/history', [DocumentController::class, 'historyForDriver'])->can('view', 'driver')->name('documents.history');
});

// Photo par URL signée de courte durée (photo_url) : pas de session, signature vérifiée.
Route::get('drivers/{driver}/photo', [DriverController::class, 'photo'])
    ->middleware('signed')
    ->name('drivers.photo');
