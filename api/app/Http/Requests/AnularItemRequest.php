<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Anulacion de un item.
 *
 * `motivo` es obligatorio y tiene largo minimo a proposito. "Sin cebolla" y
 * "el comensal se arrepintio" son motivos distintos y el restaurante los
 * distingue: uno es un error de captura, el otro es un cambio de opinion, y
 * afectan la preparacion que se descarto.
 *
 * La regla de que no se anule una comanda cobrada NO se valida aca: depende
 * del estado de la comanda, que es una regla de negocio, y vive en
 * App\Policies\ComandaItemPolicy y en MaquinaDeEstadosComanda.
 */
class AnularItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'motivo' => ['required', 'string', 'min:5', 'max:300'],
        ];
    }

    public function messages(): array
    {
        return [
            'motivo.required' => 'Escribe el motivo de la anulacion.',
            'motivo.min' => 'El motivo es demasiado corto para dejar registro.',
        ];
    }
}