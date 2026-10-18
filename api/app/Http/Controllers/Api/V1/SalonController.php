<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Zona;

/**
 * Mapa del salon.
 *
 * Devuelve zonas con sus mesas y, para cada mesa, si tiene comanda abierta.
 * Es la pantalla que el mesero tiene abierta todo el turno, asi que esta
 * penseñada para recargarse seguido: una consulta, sin N+1.
 */
class SalonController extends Controller
{
    public function index()
    {
        $zonas = Zona::query()
            ->where('activo', true)
            ->with([
                'mesas' => fn ($consulta) => $consulta
                    ->where('activo', true)
                    ->orderBy('nombre'),
            ])
            ->orderBy('orden')
            ->get();

        // Una sola consulta para todas las comandas vivas del restaurante. Sin
        // esto seria una por mesa, y con 40 mesas el mapa tarda.
        $gruposConComanda = $zonas
            ->pluck('mesas')
            ->flatten()
            ->pluck('id')
            ->all();

        $comandasPorGrupo = [];

        if ($gruposConComanda !== []) {
            $grupos = \App\Models\GrupoMesas::query()
                ->with(['comandas' => fn ($consulta) => $consulta
                    ->whereIn('estado', ['abierta', 'por_cobrar'])
                    ->with('items:id,comanda_id,estado'),
                ])
                ->whereHas('mesas', fn ($consulta) => $consulta->whereIn('mesas.id', $gruposConComanda))
                ->get()
                ->keyBy('id');

            foreach ($grupos as $grupo) {
                $comanda = $grupo->comandas->first();

                if ($comanda !== null) {
                    $comandasPorGrupo[$grupo->id] = [
                        'id' => $comanda->id,
                        'folio' => $comanda->folio,
                        'estado' => $comanda->estado->value,
                        'comensales' => $comanda->comensales,
                        'items_vivos' => $comanda->items->whereNotIn('estado', ['entregado', 'anulado'])->count(),
                        'pendientes' => $comanda->items->where('estado', 'pendiente')->count(),
                        'en_cocina' => $comanda->items->whereIn('estado', ['enviado', 'en_preparacion', 'listo'])->count(),
                    ];
                }
            }
        }

        return response()->json([
            'zonas' => $zonas->map(fn (Zona $zona): array => [
                'id' => $zona->id,
                'nombre' => $zona->nombre,
                'orden' => $zona->orden,
                'mesas' => $zona->mesas->map(fn ($mesa): array => [
                    'id' => $mesa->id,
                    'nombre' => $mesa->nombre,
                    'capacidad' => $mesa->capacidad,
                    'estado' => $mesa->estado->value,
                    'posicion_x' => $mesa->posicion_x,
                    'posicion_y' => $mesa->posicion_y,
                    'grupo_id' => $mesa->grupos()->value('grupos_mesas_id'),
                    'comanda' => $comandasPorGrupo[$mesa->grupos()->value('grupos_mesas_id')] ?? null,
                ]),
            ]),
        ]);
    }
}