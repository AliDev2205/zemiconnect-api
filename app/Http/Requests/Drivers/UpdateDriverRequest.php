<?php

namespace App\Http\Requests\Drivers;

/**
 * Modification de l'identité : mêmes champs et règles que la création,
 * chacun facultatif (seuls les champs envoyés sont modifiés).
 */
class UpdateDriverRequest extends StoreDriverRequest
{
    public function rules(): array
    {
        return array_map(
            fn (array $rules) => in_array('required', $rules, true) ? ['sometimes', ...$rules] : $rules,
            parent::rules(),
        );
    }
}
