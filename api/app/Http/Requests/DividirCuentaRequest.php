<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Division de la cuenta entre comensales.
 *
 * `partes` tiene un maximo de 40 porque es el maximo de comensales que admite
 * la apertura de comanda. Sin ese tope, una division con 999 partes crearia
 * filas que nadie va a cobrar y que nadie puede ver en la pantalla.
 */
class DividirCuentaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'partes' => ['required', 'integer', 'min:1', 'max:40'],
            'montos' => ['nullable', 'array', 'min:1', 'max:40'],
            'montos.*' => ['integer', 'min:0'],
            'propina' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ];
    }

    public function messages(): array
    {
        return [
            'partes.max' => 'Una cuenta no se divide en mas de 40 partes.',
            'montos.*' => 'Cada monto debe ser un numero entero de pesos.',
        ];
    }

    public function montosPersonalizados(): ?array
    {
        $montos = $this->input('montos');

        return is_array($montos) ? array_map('intval', $montos) : null;
    }
}