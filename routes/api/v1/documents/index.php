<?php

use App\Http\Controllers\Api\Documents\DocumentFileController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Fichiers des documents
|--------------------------------------------------------------------------
| Préfixe parent : /api/v1
| - file : connecté + documents.view_file (DocumentPolicy::viewFile) -> URL signée ;
| - download : URL signée de courte durée, sans session.
*/

Route::get('documents/{document}/file', [DocumentFileController::class, 'link'])
    ->middleware(['auth:sanctum', 'active', 'password.changed'])
    ->can('viewFile', 'document')
    ->name('documents.file');

Route::get('documents/{document}/download', [DocumentFileController::class, 'download'])
    ->middleware('signed')
    ->name('documents.download');
