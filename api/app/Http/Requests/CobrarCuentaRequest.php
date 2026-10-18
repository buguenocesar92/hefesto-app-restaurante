<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\FormaPago;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Cobro de una parte (o de la cuenta completa).
 *
 * `referencia_pos` NO se pide aca a proposito. La boleta la emite el punto de
 * venta del cliente y este sistema no la emite: aceptar aqui un numero de
 * documento que el navegador manda seria inventar un registro tributario. El
 * campo se llena cuando exista la integracion, y hasta entonces queda en
 * blanco.
 */
class CobrarCuentaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'divisiones' => ['nullable', 'array'],
            'divisiones.*' => ['integer'],
            'forma_pago' => ['required', 'string', Rule::enum(FormaPago::class)],
            'identificacion_pagador' => ['nullable', 'string', 'max:80'],
        ];
    }

    public function messages(): array
    {
        return [
            'forma_pago.required' => 'Indica con que se pago.',
            'forma_pago.enum' => 'Esa forma de pago no esta registrada.',
        ];
    }

    public function formaDePago(): FormaPago
    {
        return FormaPago::from($this->string('forma_pago')->toString());
    }

    /** @return list<int> */
    public function divisiones(): array
    {
        return array_map('intval', $this->input('divisiones', []));
    }
}