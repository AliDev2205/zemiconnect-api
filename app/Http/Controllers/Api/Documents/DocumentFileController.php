<?php

namespace App\Http\Controllers\Api\Documents;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Support\ApiResponse;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Fichiers des documents (§17) : jamais de chemin public permanent.
 * 1. GET /documents/{id}/file (connecté, documents.view_file) -> { url, expires_at } :
 *    URL signée de quelques minutes, journalisée (§21 : téléchargements de fichiers).
 * 2. GET /documents/{id}/download?signature=… -> le fichier en flux, sans session
 *    (ouvrable dans un nouvel onglet), refusé si la signature est fausse ou expirée.
 * L'agent n'a pas documents.view_file : il ne peut jamais obtenir d'URL (RG17).
 */
class DocumentFileController extends Controller
{
    public function link(Document $document): JsonResponse
    {
        abort_if($document->file_path === null, 404, 'Aucun fichier pour ce document.');

        $expiresAt = now()->addMinutes((int) config('zemiconnect.files.signed_url_minutes'));
        $url = URL::temporarySignedRoute('documents.download', $expiresAt, ['document' => $document->id]);

        Audit::record(
            'document.file_viewed',
            $document,
            driverId: $document->driver_id ?? $document->motorcycle?->currentAssignment()->value('driver_id'),
            detail: $document->type->value,
        );

        return ApiResponse::success(['url' => $url, 'expires_at' => $expiresAt->toIso8601ZuluString()]);
    }

    public function download(Document $document): StreamedResponse
    {
        $disk = Storage::disk(config('zemiconnect.files.disk'));
        abort_if($document->file_path === null || ! $disk->exists($document->file_path), 404);

        $extension = pathinfo($document->file_path, PATHINFO_EXTENSION);

        return $disk->response($document->file_path, "{$document->type->value}-{$document->id}.{$extension}", [
            'Content-Type' => $document->file_mime ?? 'application/octet-stream',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }
}
