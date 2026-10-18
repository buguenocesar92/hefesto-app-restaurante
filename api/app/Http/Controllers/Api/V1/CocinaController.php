<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\EstadoItemComanda;
use App\Http\Controllers\Controller;
use App\Http\Requests\CambiarEstadoItemRequest;
use App\Models\ComandaItem;
use App\Models\Estacion;
use App\Services\Comandas\ServicioComandas;
use App\Services\Cocina\RelojDeCocina;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Pantalla de cocina.
 *
 * EL ENDPOINT QUE MAS SE CONSULTA Y EL QUE MENOS TOCA LA BASE.
 *
 * `index` lee el reloj de Redis y calcula los tiempos en memoria. No consulta
 * `comanda_items.enviado_at` para mostrar un tiempo que cambia con cada
 * recarga. Ver App\Services\Cocina\RelojDeCocina para el criterio.
 *
 * `cambiarEstado` SI toca la base, porque un cambio de estado es un hecho que
 * queda: se escribe en la fila, en la bitacora y en el reloj. Ahi la escritura
 * es necesaria y la lectura del reloj posterior es la que mantiene la pantalla
 * al día.
 */
class CocinaController extends Controller
{
    public function __construct(
        private readonly RelojDeCocina $reloj,
        private readonly ServicioComandas $servicio,
    ) {}

    /**
     * Panel de una estacion.
     *
     * Sin estacion indicada devuelve todas las estaciones activas: es lo que
     * necesita el jefe de cocina, que ve el local entero.
     */
    public function index(?int $estacion_id = null)
    {
        $estaciones = $estacion_id !== null
            ? Estacion::query()->whereKey($estacion_id)->get()
            : Estacion::query()->where('activo', true)->orderBy('tipo')->get();

        $paneles = [];

        foreach ($estaciones as $estacion) {
            $paneles[] = [
                'estacion' => [
                    'id' => $estacion->id,
                    'nombre' => $estacion->nombre,
                    'tipo' => $estacion->tipo->value,
                    'objetivo_segundos' => $estacion->tiempo_objetivo_segundos,
                ],
                // El tiempo se calcula aqui, en memoria, a partir de lo que hay
                // en Redis. La base no participa en este calculo.
                'pedidos' => $this->reloj->panel((string) auth()->user()->tenant_id, $estacion->id),
            ];
        }

        return response()->json([
            'generado_en' => now()->toIso8601String(),
            'estaciones' => $paneles,
        ]);
    }

    /**
     * Cambia el estado de un item desde cocina.
     *
     * El tiempo se recalcula al instante en el reloj, no en la base, y el
     * cambio de estado se valida con la maquina de estados. Un item `listo` no
     * vuelve a `en_preparacion`: esa transicion no existe.
     */
    public function cambiarEstado(CambiarEstadoItemRequest $peticion, ComandaItem $item)
    {
        $this->authorize('cambiarEstado', $item);

        $nuevo = $peticion->nuevoEstado();

        // Un item recien anulado se saca de la pantalla de cocina sin tocar la
        // comanda: el mesero lo anulo, la cocina tiene que dejarlo de ver al
        // instante.
        if ($item->estado->esTerminal()) {
            $this->reloj->marcar($item, $item->estado);

            return response()->json(['item_id' => $item->id, 'estado' => $item->estado->value]);
        }

        try {
            $this->servicio->maquina()->moverItem($item->estado, $nuevo);
        } catch (DomainException $error) {
            abort(422, $error->getMessage());
        }

        DB::transaction(function () use ($item, $nuevo, $peticion): void {
            $estadoAnterior = $item->estado;

            $item->estado = $nuevo;

            $momento = now();

            if ($nuevo === EstadoItemComanda::Listo) {
                $item->listo_at = $momento;
            }

            if ($nuevo === EstadoItemComanda::Entregado) {
                $item->entregado_at = $momento;
            }

            $item->save();

            $tipoEvento = match ($nuevo) {
                EstadoItemComanda::Enviado => 'enviado_a_cocina',
                EstadoItemComanda::EnPreparacion => 'item_en_preparacion',
                EstadoItemComanda::Listo => 'item_listo',
                EstadoItemComanda::Entregado => 'item_entregado',
                default => 'item_anulado',
            };

            $this->servicio->eventoDeItem(
                $item->comanda,
                $peticion->user(),
                $tipoEvento,
                $item,
                ['estado_anterior' => $estadoAnterior->value],
            );
        });

        $item = $item->fresh();

        $this->reloj->marcar($item, $nuevo);

        return response()->json([
            'item_id' => $item->id,
            'estado' => $item->estado->value,
            'en_pantalla' => $item->estado->estaEnPantalla(),
        ]);
    }
}