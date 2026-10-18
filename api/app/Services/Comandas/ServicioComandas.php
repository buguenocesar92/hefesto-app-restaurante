<?php

declare(strict_types=1);

namespace App\Services\Comandas;

use App\Enums\EstadoComanda;
use App\Enums\EstadoItemComanda;
use App\Enums\EstadoMesa;
use App\Models\Comanda;
use App\Models\ComandaEvento;
use App\Models\Mesa;
use App\Models\Turno;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;

/**
 * Apertura de comanda y transiciones de estado.
 *
 * TODAS las operaciones que mueven el estado de una comanda pasan por aqui, y
 * todas escriben su evento en la bitacora dentro de la misma transaccion. No
 * existe una ruta que cambie un estado sin registrar quien lo hizo y cuando:
 * un reclamo por comida cobrada no cobrada no se puede investigar sin eso.
 */
class ServicioComandas
{
    public function __construct(
        private readonly GeneradorDeFolio $generador,
        private readonly MaquinaDeEstadosComanda $maquina,
    ) {}

    /**
     * Abre una comanda para un grupo de mesas.
     *
     * El grupo tiene que estar libre. La comprobacion va DENTRO de la
     * transaccion y con `lockForUpdate`, porque dos meseros pueden abrir mesa
     * al mismo tiempo y sin bloqueo el segundo crearia una comanda sobre un
     * grupo que ya esta ocupado.
     */
    public function abrir(
        GrupoMesas $grupo,
        int $comensales,
        Usuario $usuario,
        ?string $notas = null,
        ?Turno $turno = null,
    ): Comanda {
        return DB::transaction(function () use ($grupo, $comensales, $usuario, $notas, $turno): Comanda {
            $grupoBloqueado = GrupoMesas::query()
                ->whereKey($grupo->id)
                ->lockForUpdate()
                ->firstOrFail();

            $comandaVigente = Comanda::query()
                ->where('grupos_mesas_id', $grupoBloqueado->id)
                ->whereIn('estado', [EstadoComanda::Abierta->value, EstadoComanda::PorCobrar->value])
                ->first();

            if ($comandaVigente !== null) {
                throw new \DomainException(
                    'Las mesas ' . $grupoBloqueado->nombre . ' ya tienen la comanda '
                    . $comandaVigente->folio . ' abierta.'
                );
            }

            $comanda = Comanda::query()->create([
                'grupos_mesas_id' => $grupoBloqueado->id,
                'folio' => $this->generador->siguiente($grupoBloqueado->tenant_id),
                'estado' => EstadoComanda::Abierta,
                'comensales' => $comensales,
                'abierta_por_usuario_id' => $usuario->id,
                'abierta_en_turno_id' => $turno?->id,
                'notas' => $notas,
                'abierta_at' => now(),
            ]);

            $this->actualizarEstadoMesas($grupoBloqueado, EstadoMesa::Ocupada);

            $this->registrarEvento($comanda, 'comanda_abierta', $usuario, null, [
                'mesas' => $grupoBloqueado->nombre,
                'comensales' => $comensales,
            ]);

            return $comanda->fresh();
        });
    }

    /** Mueve la comanda a un estado nuevo, validando la transicion. */
    public function cambiarEstado(Comanda $comanda, EstadoComanda $nuevo, Usuario $usuario, ?string $motivo = null): Comanda
    {
        $this->maquina->moverComanda($comanda->estado, $nuevo);

        return DB::transaction(function () use ($comanda, $nuevo, $usuario, $motivo): Comanda {
            $comanda->estado = $nuevo;

            if ($nuevo === EstadoComanda::Cobrada) {
                $comanda->cobrada_at = now();
            }

            if ($nuevo === EstadoComanda::Anulada) {
                $comanda->anulada_at = now();
                $comanda->anulada_por_usuario_id = $usuario->id;
                $comanda->motivo_anulacion = $motivo;
            }

            if ($nuevo === EstadoComanda::PorCobrar) {
                $comanda->cerrada_at = now();

                if ($comanda->grupoMesas !== null) {
                    $this->actualizarEstadoMesas($comanda->grupoMesas, EstadoMesa::PorCerrar);
                }
            }

            $comanda->save();

            $tipoEvento = match ($nuevo) {
                EstadoComanda::PorCobrar => 'comanda_por_cobrar',
                EstadoComanda::Cobrada => 'comanda_cobrada',
                EstadoComanda::Anulada => 'comanda_anulada',
                EstadoComanda::Abierta => 'comanda_reabierta',
            };

            $this->registrarEvento($comanda, $tipoEvento, $usuario, null, ['motivo' => $motivo]);

            return $comanda->fresh();
        });
    }

    /** Marca las mesas fisicas del grupo con el estado indicado. */
    private function actualizarEstadoMesas(GrupoMesas $grupo, EstadoMesa $estado): void
    {
        $ids = $grupo->mesas()->pluck('mesas.id');

        if ($ids->isEmpty()) {
            return;
        }

        Mesa::query()->whereIn('id', $ids)->update(['estado' => $estado]);
    }

    private function registrarEvento(
        Comanda $comanda,
        string $tipo,
        Usuario $usuario,
        ?\App\Models\ComandaItem $item = null,
        array $detalle = [],
    ): ComandaEvento {
        return ComandaEvento::query()->create([
            'comanda_id' => $comanda->id,
            'comanda_item_id' => $item?->id,
            'usuario_id' => $usuario->id,
            'tipo' => $tipo,
            'resumen' => $item !== null ? $item->lineaParaCocina() : $comanda->etiqueta(),
            'detalle' => $detalle,
            'ocurrio_en' => now(),
        ]);
    }

    public function eventoDeItem(Comanda $comanda, Usuario $usuario, string $tipo, \App\Models\ComandaItem $item, array $detalle = []): ComandaEvento
    {
        return $this->registrarEvento($comanda, $tipo, $usuario, $item, $detalle);
    }

    public function maquina(): MaquinaDeEstadosComanda
    {
        return $this->maquina;
    }
}