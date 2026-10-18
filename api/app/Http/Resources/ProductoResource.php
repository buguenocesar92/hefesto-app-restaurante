<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Vista de un producto con sus grupos de modificadores para el mesero. */
class ProductoResource extends JsonResource
{
    public function toArray(Request $peticion): array
    {
        return [
            'id' => $this->id,
            'codigo' => $this->codigo,
            'nombre' => $this->nombre,
            'descripcion' => $this->descripcion,
            'precio' => $this->precio,
            'unidad' => $this->unidad,
            'tipo_linea' => $this->tipo_linea->value,
            'estacion_id' => $this->estacion_id,
            'estacion' => $this->whenLoaded('estacion', fn () => $this->estacion?->nombre),
            'tiempo_objetivo_segundos' => $this->objetivoSegundos(),
            'acepta_modificadores' => $this->acepta_modificadores,
            'activo' => $this->activo,
            'grupos_modificadores' => $this->whenLoaded('gruposModificadores.grupo.modificadores', fn () => $this->gruposModificadores->map(fn ($vinculo): array => [
                'grupo_id' => $vinculo->grupos_modificadores_id,
                'nombre' => $vinculo->grupo?->nombre,
                'seleccion' => $vinculo->grupo?->seleccion,
                'obligatorio' => $vinculo->obligatorio ?? $vinculo->grupo?->obligatorio,
                'modificadores' => $vinculo->grupo?->modificadores->map(fn ($modificador): array => [
                    'id' => $modificador->id,
                    'nombre' => $modificador->nombre,
                    'tipo' => $modificador->tipo->value,
                    'precio' => $modificador->precio,
                ])->all() ?? [],
            ])->all()),
        ];
    }
}