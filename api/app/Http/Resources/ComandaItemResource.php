<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Vista de un item para el salon.
 *
 * Deliberadamente NO expone los tiempos en vivo. El tiempo en pantalla es
 * cosa de la pantalla de cocina y se calcula contra Redis; incluirlo aqui
 * haria que el salon creyera que tiene un reloj propio y lo calcularia
 * distinto.
 */
class ComandaItemResource extends JsonResource
{
    public function toArray(Request $peticion): array
    {
        return [
            'id' => $this->id,
            'comanda_id' => $this->comanda_id,
            'producto_id' => $this->producto_id,
            'nombre' => $this->nombre_producto,
            'cantidad' => $this->cantidad,
            'precio_unitario' => $this->precio_unitario,
            'subtotal' => $this->subtotal(),
            'notas_preparacion' => $this->notas_preparacion,
            'estado' => $this->estado->value,
            'estado_label' => $this->estado->etiqueta(),
            'tipo_linea' => $this->tipo_linea->value,
            'estacion_id' => $this->estacion_id,
            'tiempo_objetivo_segundos' => $this->tiempo_objetivo_segundos,
            'modificadores' => $this->whenLoaded('modificadores', fn () => $this->modificadores->map(fn ($modificador): array => [
                'id' => $modificador->id,
                'nombre' => $modificador->nombre,
                'tipo' => $modificador->tipo->value,
                'precio' => $modificador->precio,
                'ingrediente' => $modificador->ingrediente,
            ])->all()),
            'enviado_at' => $this->enviado_at?->toIso8601String(),
            'listo_at' => $this->listo_at?->toIso8601String(),
            'entregado_at' => $this->entregado_at?->toIso8601String(),
            'motivo_anulacion' => $this->motivo_anulacion,
        ];
    }
}