<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Vista de la comanda para el salon. */
class ComandaResource extends JsonResource
{
    public function toArray(Request $peticion): array
    {
        return [
            'id' => $this->id,
            'folio' => $this->folio,
            'etiqueta' => $this->etiqueta(),
            'estado' => $this->estado->value,
            'comensales' => $this->comensales,
            'notas' => $this->notas,
            'mesas' => $this->whenLoaded('grupoMesas.mesas', fn () => $this->grupoMesas->mesas->map(fn ($mesa): array => [
                'id' => $mesa->id,
                'nombre' => $mesa->nombre,
                'capacidad' => $mesa->capacidad,
            ])->all()),
            'combinacion' => $this->whenLoaded('grupoMesas', fn () => (bool) $this->grupoMesas->es_combinacion),
            'abierta_por' => $this->whenLoaded('abiertaPor', fn () => $this->abiertaPor?->nombreCompleto()),
            'abierta_at' => $this->abierta_at?->toIso8601String(),
            'cobrada_at' => $this->cobrada_at?->toIso8601String(),
            'anulada_at' => $this->anulada_at?->toIso8601String(),
            'motivo_anulacion' => $this->motivo_anulacion,
            'items' => ComandaItemResource::collection($this->whenLoaded('items')),
            'cuenta' => $this->whenLoaded('cuenta'),
            'admite_items' => $this->estado->admiteItems(),
            'admite_anulacion' => $this->estado->admiteAnulacion(),
        ];
    }
}