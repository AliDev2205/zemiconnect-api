<?php

namespace App\Exceptions;

use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Règle métier refusée (transition interdite, moto affectée, dossier incomplet…).
 * Levée par les services, dans leur transaction (qui est donc annulée), et
 * rendue en 422 { status: "error", message, code, errors? }. Justification :
 * les services restent indépendants de HTTP tout en donnant un code lisible au frontend.
 */
class BusinessRuleException extends RuntimeException
{
    /**
     * @param  array<string, list<string>>|null  $errors
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly ?array $errors = null,
    ) {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return ApiResponse::error($this->getMessage(), 422, $this->errors, $this->errorCode);
    }

    public static function invalidTransition(string $message): self
    {
        return new self('invalid_transition', $message, ['status' => [$message]]);
    }

    public static function driverArchived(): self
    {
        return new self('driver_archived', 'Ce dossier est archivé : il ne peut plus être modifié.');
    }

    public static function motorcycleArchived(string $field = 'motorcycle_id'): self
    {
        $message = 'Cette moto est archivée.';

        return new self('motorcycle_archived', $message, [$field => [$message]]);
    }
}
