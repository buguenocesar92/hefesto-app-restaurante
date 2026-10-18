<?php

declare(strict_types=1);

namespace App\Services\Comandas;

use App\Models\Comanda;
use App\Models\GrupoMesas;
use App\Models\Turno;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Genera el folio correlativo de comanda dentro del restaurante.
 *
 * El correlativo usa `INSERT ... ON CONFLICT DO UPDATE ... RETURNING` sobre
 * `contadores_folio`, que en PostgreSQL hace el bloqueo de fila en la base. La
 * alternativa (leer el valor, sumarle uno, guardar) tiene una ventana en la que
 * dos meseros que abren mesa al mismo tiempo sacan el mismo folio, y dos
 * comandas con el mismo numero en un local es un problema de servicio que nadie
 * sabe despues si cobro bien.
 *
 * Verificado contra la documentacion de PostgreSQL para `INSERT ... ON
 * CONFLICT` y `RETURNING`.
 */
class GeneradorDeFolio
{
    private const CONCEPTO = 'comanda';

    public function siguiente(string $restauranteId): int
    {
        $fila = DB::selectOne(
            'INSERT INTO public.contadores_folio (tenant_id, concepto, ultimo_valor, created_at, updated_at)
             VALUES (?, ?, 1, now(), now())
             ON CONFLICT (tenant_id, concepto)
             DO UPDATE SET ultimo_valor = public.contadores_folio.ultimo_valor + 1, updated_at = now()
             RETURNING ultimo_valor',
            [$restauranteId, self::CONCEPTO]
        );

        return (int) $fila->ultimo_valor;
    }
}