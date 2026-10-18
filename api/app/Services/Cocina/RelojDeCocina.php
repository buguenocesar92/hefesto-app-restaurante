<?php

declare(strict_types=1);

namespace App\Services\Cocina;

use App\Enums\EstadoItemComanda;
use App\Models\ComandaItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Redis;

/**
 * El reloj de cocina: los tiempos se calculan EN MEMORIA, no en la base.
 *
 * POR QUE NO EN LA BASE
 * ---------------------
 * La pantalla de cocina se consulta cada pocos segundos, varias a la vez, y
 * muestra un tiempo que cambia literalmente con cada recarga. Si el tiempo se
 * calculara con `now() - enviado_at` en PostgreSQL, cada recarga seria una
 * consulta a la base mas trabajo de base para calcular algo que ya se sabe:
 * que hora es. En un local con ocho comandas abiertas son decenas de consultas
 * por minuto para obtener un numero que el servidor ya tiene en la memoria.
 *
 * La base guarda `enviado_at`, `listo_at` y `entregado_at` porque son
 * HISTORICOS: el reporte de "el plato que mas tardo" y la bitacora los
 * necesitan dentro de la transaccion, y tienen que seguir ahi si Redis se
 * cae. Lo que NO se hace es consultarlos en vivo.
 *
 * DONDE VIVE EL TIEMPO
 * --------------------
 * Redis guarda dos estructuras por item:
 *
 *  - Hash `cocina:item:{restaurante}:{item}` con las marcas de tiempo
 *    (enviado, en_preparacion, listo, entregado) en epoch en segundos.
 *  - Conjunto ordenado `cocina:cola:{restaurante}:{estacion}`, con el id del
 *    item como miembro y la marca de envio como puntaje. Es lo que permite
 *    pedir "los 20 pedidos mas viejos de esta estacion" en una sola operacion
 *    O(log n), sin traer toda la cola.
 *
 * Cuando el item llega a un estado terminal, sale del conjunto ordenado pero
 * su hash se conserva el tiempo de retencion, para poder reconstruir la linea
 * de tiempo si hace falta.
 *
 * SI SE PIERDE REDIS
 * ------------------
 * Redis guarda estado VOLATIL a proposito: si se pierde, la base sigue
 * teniendo los `enviado_at` y el comando `restaurante:reconstruir-reloj`
 * repuebla el reloj desde la base. Perder Redis cuesta una reconstruccion; no
 * cuesta perder el historico ni perder el tiempo de una comanda abierta.
 */
class RelojDeCocina
{
    /** Dias que se conserva la linea de tiempo de un item ya terminal. */
    private const RETENCION_HORAS = 72;

    public function __construct(private readonly string $conexion = 'default') {}

    /**
     * Registra la marca de tiempo de una transicion de estado.
     *
     * Se llama dentro de la misma operacion que cambia el estado en la base.
     * Si la transaccion de la base falla despues, Redis puede quedar con una
     * marca de mas: es el fallo barato (una linea de mas en la pantalla, que
     * se descarta en la siguiente carga). Al reves, una marca que falta en
     * Redis se pierde para siempre, y un plato sin tiempo no se puede ordenar.
     */
    public function marcar(ComandaItem $item, EstadoItemComanda $estado, ?CarbonImmutable $momento = null): void
    {
        $momento ??= CarbonImmutable::now();
        $clave = $this->claveItem($item->tenant_id, $item->id);

        Redis::connection($this->conexion)->hset($clave, [
            $this->campo($estado) => (string) $momento->getTimestamp(),
        ]);

        Redis::connection($this->conexion)->expire($clave, self::RETENCION_HORAS * 3600);

        $cola = $this->claveCola($item->tenant_id, $item->estacion_id);

        if ($estado->estaEnPantalla()) {
            // La cola se ordena por la marca de ENVIO, no por la de esta
            // transicion: un plato que estuvo mucho en preparacion y acaba de
            // quedar listo sigue siendo viejo, y por eso sube en la pantalla.
            $enviado = Redis::connection($this->conexion)->hget($clave, 'enviado');

            if ($enviado === null || $enviado === false) {
                $enviado = (string) $momento->getTimestamp();
                Redis::connection($this->conexion)->hset($clave, ['enviado' => $enviado]);
            }

            Redis::connection($this->conexion)->zadd($cola, $enviado, (string) $item->id);
        } else {
            Redis::connection($this->conexion)->zrem($cola, (string) $item->id);
        }
    }

    /**
     * Panel de una estacion: pedidos ordenados por antiguedad, con el tiempo
     * calculado aqui, en memoria, sin tocar la base.
     *
     * @return list<array{
     *     item_id: int,
     *     comanda_id: int,
     *     estado: string,
     *     nombre: string,
     *     cantidad: int,
     *     comensales: int,
     *     notas: string|null,
     *     modificadores: list<string>,
     *     segundos: int,
     *     objetivo_segundos: int,
     *     atrasado: bool
     * }>
     */
    public function panel(int $restauranteId, int $estacionId, int $limite = 30, ?CarbonImmutable $ahora = null): array
    {
        $ahora ??= CarbonImmutable::now();
        $cola = $this->claveCola($restauranteId, $estacionId);
        $redis = Redis::connection($this->conexion);

        // ZRANGE con scores devuelve los mas antiguos primero, que es el
        // orden en que la cocina necesita verlos.
        $miembros = $redis->zrange($cola, 0, $limite - 1, true);

        if ($miembros === [] || $miembros === false) {
            return [];
        }

        $ids = array_map(static fn ($id): int => (int) $id, array_keys($miembros));

        // Una sola consulta trae los items, el nombre de la comanda y los
        // modificadores. La RLS sigue aplicando: el filtro por tenant no es
        // opcional y no se puede apagar con un parametro.
        $items = ComandaItem::query()
            ->with(['comanda', 'modificadores'])
            ->whereIn('id', $ids)
            ->where('estacion_id', $estacionId)
            ->orderBy('id')
            ->get()
            ->keyBy('id');

        $filas = [];

        foreach ($ids as $id) {
            $item = $items->get($id);

            // Puede faltar si el item fue anulado entre la lectura de la cola
            // y esta consulta. No es un error: se salta.
            if ($item === null) {
                continue;
            }

            $enviado = (int) ($miembros[$id] ?? $ahora->getTimestamp());
            $segundos = max(0, $ahora->getTimestamp() - $enviado);

            $filas[] = [
                'item_id' => $item->id,
                'comanda_id' => $item->comanda_id,
                'estado' => $item->estado->value,
                'nombre' => $item->nombre_producto,
                'cantidad' => $item->cantidad,
                'comensales' => $item->comanda?->comensales ?? 1,
                'notas' => $item->notas_preparacion,
                'modificadores' => $item->modificadores->map(
                    static fn ($modificador): string => $modificador->nombre
                )->all(),
                'segundos' => $segundos,
                'objetivo_segundos' => $item->tiempo_objetivo_segundos,
                // "Atrasado" se define contra el objetivo del item, no contra
                // el promedio de la semana. Es un umbral de gestion interna.
                'atrasado' => $segundos > $item->tiempo_objetivo_segundos,
            ];
        }

        return $filas;
    }

    /**
     * Repuebla el reloj desde la base, para cuando Redis se perdio o se
     * reinicio. Es lo que hace el comando `restaurante:reconstruir-reloj`.
     *
     * @return int cantidad de items reconstruidos
     */
    public function reconstruirDesdeBase(string $restauranteId): int
    {
        $items = ComandaItem::query()
            ->whereIn('estado', [
                EstadoItemComanda::Enviado->value,
                EstadoItemComanda::EnPreparacion->value,
                EstadoItemComanda::Listo->value,
            ])
            ->get();

        $reconstruidos = 0;

        foreach ($items as $item) {
            $clave = $this->claveItem($item->tenant_id, $item->id);

            foreach (['enviado', 'en_preparacion', 'listo', 'entregado'] as $campo) {
                $columna = $campo . '_at';

                if ($item->{$columna} !== null) {
                    Redis::connection($this->conexion)->hset(
                        $clave,
                        [$campo => (string) $item->{$columna}->getTimestamp()]
                    );
                }
            }

            Redis::connection($this->conexion)->expire($clave, self::RETENCION_HORAS * 3600);
            $redis = Redis::connection($this->conexion);
            $enviado = $redis->hget($clave, 'enviado');

            if ($enviado !== null && $enviado !== false) {
                $redis->zadd(
                    $this->claveCola($item->tenant_id, $item->estacion_id),
                    $enviado,
                    (string) $item->id
                );
            }

            $reconstruidos++;
        }

        return $reconstruidos;
    }

    private function campo(EstadoItemComanda $estado): string
    {
        return match ($estado) {
            EstadoItemComanda::Enviado => 'enviado',
            EstadoItemComanda::EnPreparacion => 'en_preparacion',
            EstadoItemComanda::Listo => 'listo',
            EstadoItemComanda::Entregado => 'entregado',
            default => 'estado_' . $estado->value,
        };
    }

    private function claveItem(string $restauranteId, int $itemId): string
    {
        return 'cocina:item:' . $restauranteId . ':' . $itemId;
    }

    private function claveCola(string $restauranteId, int $estacionId): string
    {
        return 'cocina:cola:' . $restauranteId . ':' . $estacionId;
    }
}