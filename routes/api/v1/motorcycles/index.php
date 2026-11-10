<?php

use App\Http\Controllers\Api\Documents\DocumentController;
use App\Http\Controllers\Api\Motorcycles\AssignmentController;
use App\Http\Controllers\Api\Motorcycles\MotorcycleController;
use App\Models\Document;
use App\Models\Motorcycle;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Motos et affectations (GE5, GE6)
|--------------------------------------------------------------------------
| Préfixe parent : /api/v1. Autorisations : MotorcyclePolicy, DocumentPolicy.
*/

Route::middleware(['auth:sanctum', 'active', 'password.changed'])->prefix('motorcycles')->name('motorcycles.')->group(function () {

    Route::get('/', [MotorcycleController::class, 'index'])->can('viewAny', Motorcycle::class)->name('index');
    Route::post('/', [MotorcycleController::class, 'store'])->can('create', Motorcycle::class)->name('store');
    Route::get('{motorcycle}', [MotorcycleController::class, 'show'])->can('view', 'motorcycle')->name('show');
    Route::put('{motorcycle}', [MotorcycleController::class, 'update'])->can('update', 'motorcycle')->name('update');
    Route::post('{motorcycle}/archive', [MotorcycleController::class, 'archive'])->can('update', 'motorcycle')->name('archive');

    Route::get('{motorcycle}/assignments', [AssignmentController::class, 'forMotorcycle'])->can('view', 'motorcycle')->name('assignments');

    Route::post('{motorcycle}/documents', [DocumentController::class, 'storeForMotorcycle'])->can('create', Document::class)->name('documents.store');
    Route::get('{motorcycle}/documents/history', [DocumentController::class, 'historyForMotorcycle'])->can('view', 'motorcycle')->name('documents.history');
});
