<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Agregar un item a la comanda.
 *
 * `modificadores` es una lista de ids, no texto. La validacion de que esos
 * modificadores tengan sentido para ESE producto la hace
 * App\Services\Comandas\CalculadoraDePrecios, porque depende de la receta y
 * de los grupos del producto, no solo de que el id exista.
 *
 * `enviar_a_cocina` decide si el item nace en `pendiente` (el mesero todavia
 * esta corrigiendo) o se manda de inmediato a pantalla de cocina. Es la
 * diferencia entre "anotar" y "enviar", que en un local son dos gestos
 * distintos.
 */
class StoreItemComandaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'producto_id' => ['required', 'integer', 'exists:productos,id'],
            'cantidad' => ['required', 'integer', 'min:1', 'max:50'],
            'modificadores' => ['nullable', 'array', 'max:10'],
            'modificadores.*' => ['integer', Rule::exists('modificadores', 'id')],
            'notas_preparacion' => ['nullable', 'string', 'max:300'],
            'enviar_a_cocina' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'producto_id.exists' => 'Ese producto no existe en este restaurante.',
            'cantidad.min' => 'La cantidad debe ser al menos 1.',
            'modificadores.max' => 'Un item admite hasta 10 modificadores.',
            'notas_preparacion.max' => 'La nota de preparacion es demasiado larga.',
        ];
    }

    public function identificadoresModificadores(): array
    {
        return array_map('intval', $this->input('modificadores', []));
    }

    public function enviarACocina(): bool
    {
        return $this->boolean('enviar_a_cocina');
    }
}