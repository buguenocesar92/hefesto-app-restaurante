<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\EstadoItemComanda;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Cambio de estado de un item desde la pantalla de cocina.
 *
 * El `estado` se valida contra el enum, no contra una lista de texto en el
 * controlador: agregar un estado nuevo al enum y olvidar el endpoint seria un
 * error de desarrollo, no un dato que llegue desde el navegador.
 */
class CambiarEstadoItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'estado' => ['required', 'string', Rule::enum(EstadoItemComanda::class)],
        ];
    }

    public function nuevoEstado(): EstadoItemComanda
    {
        return EstadoItemComanda::from($this->string('estado')->toString());
    }
}