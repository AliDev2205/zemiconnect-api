<?php

namespace App\Http\Requests\Motorcycles;

/**
 * Modification : mêmes règles que la création, champs facultatifs.
 */
class UpdateMotorcycleRequest extends StoreMotorcycleRequest
{
    public function rules(): array
    {
        return array_map(
            fn (array $rules) => in_array('required', $rules, true) ? ['sometimes', ...$rules] : $rules,
            parent::rules(),
        );
    }
}
