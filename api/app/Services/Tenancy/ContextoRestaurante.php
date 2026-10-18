<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Contexto de tenant (restaurante) de la peticion.
 *
 * La RLS de PostgreSQL (migracion 2026_10_17_000013) lee el tenant desde la
 * variable de sesion `app.tenant_id`. Esta clase es la UNICA que la escribe.
 *
 * Es el mismo mecanismo de apps/portal-autoservicio, con el nombre del dominio
 * cambiado: aqui el tenant es un restaurante.
 *
 * ALCANCE DE TRANSACCION
 * ----------------------
 * Se usa `set_config(..., true)`: el valor desaparece al confirmar o revertir
 * la transaccion. Con un `SET` normal (ambito de sesion) el valor sobreviviria,
 * y el worker de colas, que reutiliza la misma conexion para trabajos de
 * restaurantes distintos, podria leer datos de otro local. Ese es el fallo que
 * este diseno evita, y por eso `establecer()` EXIGE estar dentro de una
 * transaccion y lanza excepcion si no lo esta.
 */
class ContextoRestaurante
{
    /** Variable de sesion de PostgreSQL que lee la politica RLS. */
    public const VARIABLE = 'app.tenant_id';

    /**
     * @throws RuntimeException si no hay una transaccion abierta.
     */
    public function establecer(string $tenantId): void
    {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException(
                'No se puede fijar el contexto de restaurante fuera de una transaccion. '
                . 'Use App\Services\Tenancy\ContextoRestaurante::ejecutarAislado().'
            );
        }

        DB::select('SELECT set_config(?, ?, true)', [self::VARIABLE, $tenantId]);
    }

    /**
     * Ejecuta una operacion dentro de una transaccion con el tenant fijo.
     *
     * @template T
     *
     * @param  Closure(): T  $operacion
     * @return T
     */
    public function ejecutarAislado(string $tenantId, Closure $operacion): mixed
    {
        return DB::transaction(function () use ($tenantId, $operacion) {
            $this->establecer($tenantId);

            return $operacion();
        });
    }

    /**
     * Lee la variable de sesion sin pasar por `public.app_tenant_id()`, que
     * lanza excepcion cuando no hay contexto. Sirve para diagnostico.
     */
    public function restauranteEnConexion(): ?string
    {
        $fila = DB::selectOne('SELECT current_setting(?, true) AS valor', [self::VARIABLE]);

        $valor = $fila->valor ?? null;

        return $valor === null || $valor === '' ? null : $valor;
    }
}