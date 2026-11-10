<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Écriture uniforme du journal d'audit (RG21, §21) : une seule forme de
 * `properties` pour que le fil d'historique et les journaux se lisent sans
 * cas particuliers. Justification : chaque écriture métier journalise la même
 * structure (action, avant / après, motif, conducteur concerné) ; l'IP est
 * ajoutée par App\Models\Activity.
 *
 * properties = { old: {...}, attributes: {...}, reason?, driver_id?, label?, detail? }
 */
final class Audit
{
    public const LOG_NAME = 'audit';

    /**
     * @param  array<string, mixed>  $old  valeurs avant
     * @param  array<string, mixed>  $new  valeurs après
     */
    public static function record(
        string $action,
        Model $subject,
        array $old = [],
        array $new = [],
        ?string $reason = null,
        ?int $driverId = null,
        ?string $label = null,
        ?string $detail = null,
    ): void {
        activity()
            ->useLog(self::LOG_NAME)
            ->event($action)
            ->performedOn($subject)
            ->causedBy(Auth::user())
            ->withProperties(array_filter([
                'old' => self::normalize($old),
                'attributes' => self::normalize($new),
                'reason' => $reason,
                'driver_id' => $driverId,
                'label' => $label,
                'detail' => $detail,
            ], fn ($value) => $value !== null && $value !== []))
            ->log($action);
    }

    /**
     * Valeurs avant / après d'un modèle modifié mais PAS ENCORE enregistré
     * (à appeler avant save(), qui resynchronise les valeurs d'origine).
     *
     * @param  list<string>  $hidden  champs à ne jamais journaliser
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public static function diff(Model $model, array $hidden = []): array
    {
        $old = [];
        $new = [];

        foreach (array_keys($model->getDirty()) as $key) {
            if (in_array($key, ['updated_at', 'updated_by', ...$hidden], true)) {
                continue;
            }
            $old[$key] = $model->getOriginal($key);
            $new[$key] = $model->getAttribute($key);
        }

        return [$old, $new];
    }

    /**
     * Enums et dates en valeurs JSON simples.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private static function normalize(array $values): array
    {
        return array_map(function ($value) {
            return match (true) {
                $value instanceof \BackedEnum => $value->value,
                $value instanceof \DateTimeInterface => $value->format($value->format('His') === '000000' ? 'Y-m-d' : DATE_ATOM),
                default => $value,
            };
        }, $values);
    }
}
