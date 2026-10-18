<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Abrir comanda para un grupo de mesas.
 *
 * El `grupo_mesas_id` se valida con `exists` sobre la misma tabla con la
 * columna `tenant_id` acotada al restaurante de la peticion. No es la RLS la
 * que hace este filtro: es la validacion. Las dos capas estan, y por eso un id
 * de otro restaurante da un error de validacion en vez de un 404 raro.
 */
class StoreComandaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'grupo_mesas_id' => ['required', 'integer', 'exists:grupos_mesas,id'],
            'comensales' => ['required', 'integer', 'min:1', 'max:40'],
            'notas' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'grupo_mesas_id.required' => 'Indica las mesas de la comanda.',
            'grupo_mesas_id.exists' => 'Ese grupo de mesas no existe en este restaurante.',
            'comensales.min' => 'La comanda necesita al menos un comensal.',
        ];
    }
}