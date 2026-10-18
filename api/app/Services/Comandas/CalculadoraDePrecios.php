<?php

declare(strict_types=1);

namespace App\Services\Comandas;

use App\Models\Modificador;
use App\Models\Producto;
use DomainException;

/**
 * Validacion y calculo del precio de un item con sus modificadores.
 *
 * EL PROBLEMA QUE RESUELVE
 * ------------------------
 * Un "extra queso" no es un texto con precio pegado. Es una fila de
 * `modificadores` que ademas tiene que tener sentido para ESE producto:
 *
 *  - Si el producto no acepta modificadores, no hay nada que validar: se
 *    rechaza.
 *  - Un modificador de tipo `remocion` tiene que apuntar a un ingrediente que
 *    este EN LA RECETA del producto. Si el producto no lleva cebolla, "sin
 *    cebolla" no es una peticion valida y hay que decirlo, no aceptarlo en
 *    silencio: un modificador que se acepta y no se puede cumplir es peor que
 *    uno que se rechaza, porque la cocina lo ve en pantalla igual.
 *  - Un grupo `unica` con `obligatorio` exige exactamente una opcion, y uno
 *    `multiple` admite cero o mas. Un grupo obligatorio con dosElecciones en
 *    un grupo de eleccion unica es un error de captura, no una preferencia.
 *
 * PRECIO
 * ------
 * El precio del item es `(precio del producto + suma de modificadores) x
 * cantidad`. El precio del producto se COPIA al item y el del modificador se
 * COPIA a `comanda_item_modificadores`: si manana suben el extra queso, las
 * comandas de ayer tienen que seguir cuadrando con lo que se cobro ayer.
 *
 * `SinCosto` aporta 0 al precio pero sigue apareciendo en pantalla de cocina,
 * porque "sin hielo" o "bien pasado" cambian lo que se hace aunque no cueste
 * un peso.
 */
class CalculadoraDePrecios
{
    /**
     * @param  list<int>  $idsModificadores
     *
     * @throws DomainException si alguna combinacion no es valida.
     */
    public function validar(Producto $producto, array $idsModificadores): void
    {
        if ($idsModificadores === []) {
            return;
        }

        if (! $producto->acepta_modificadores) {
            throw new DomainException(
                'El producto "' . $producto->nombre . '" no acepta modificadores.'
            );
        }

        $ids = array_values(array_unique($idsModificadores));
        $modificadores = Modificador::query()
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        // Un id que no existe no se ignora en silencio.
        if ($modificadores->count() !== count($ids)) {
            throw new DomainException('Uno de los modificadores indicados no existe en este restaurante.');
        }

        $permitidos = $producto->gruposModificadores()
            ->with('grupo')
            ->get()
            ->pluck('grupo')
            ->keyBy('id');

        $elegidosPorGrupo = [];

        foreach ($modificadores as $modificador) {
            $grupo = $permitidos->get($modificador->grupos_modificadores_id);

            if ($grupo === null) {
                throw new DomainException(
                    'El modificador "' . $modificador->nombre . '" no aplica al producto "'
                    . $producto->nombre . '".'
                );
            }

            if ($modificador->tipo->admiteIngrediente() && $modificador->ingrediente_id !== null) {
                $this->verificarQueElIngredienteEstaEnLaReceta($producto, $modificador);
            }

            $elegidosPorGrupo[$grupo->id][] = $modificador;
        }

        foreach ($this->gruposObligatorios($producto, $permitidos) as $grupo) {
            $elegidos = $elegidosPorGrupo[$grupo->id] ?? [];

            if ($elegidos === []) {
                throw new DomainException(
                    'Falta elegir "' . $grupo->nombre . '" para "' . $producto->nombre . '".'
                );
            }

            if ($grupo->seleccion === 'unica' && count($elegidos) > 1) {
                throw new DomainException(
                    'En "' . $grupo->nombre . '" se elige una sola opción y se eligieron '
                    . count($elegidos) . '.'
                );
            }
        }
    }

    /**
     * Precio unitario del item ya con modificadores, en pesos enteros.
     *
     * @param  list<int>  $idsModificadores
     *
     * @throws DomainException si alguna combinacion no es valida.
     */
    public function precioUnitario(Producto $producto, array $idsModificadores, int $cantidad = 1): int
    {
        if ($cantidad < 1) {
            throw new DomainException('La cantidad debe ser al menos 1.');
        }

        $this->validar($producto, $idsModificadores);

        $ids = array_values(array_unique($idsModificadores));

        $sumaModificadores = $ids === []
            ? 0
            : (int) Modificador::query()->whereIn('id', $ids)->sum('precio');

        return ($producto->precio + $sumaModificadores) * $cantidad;
    }

    private function verificarQueElIngredienteEstaEnLaReceta(Producto $producto, Modificador $modificador): void
    {
        if ($modificador->tipo !== \App\Enums\TipoModificador::Remocion) {
            return;
        }

        $enReceta = $producto->ingredientes()
            ->where('ingrediente_id', $modificador->ingrediente_id)
            ->exists();

        if (! $enReceta) {
            throw new DomainException(
                'No se puede quitar "' . $modificador->nombre . '" porque "'
                . $producto->nombre . '" no lleva ese ingrediente en su receta.'
            );
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<int, \App\Models\GrupoModificadores>  $permitidos
     * @return list<\App\Models\GrupoModificadores>
     */
    private function gruposObligatorios(Producto $producto, $permitidos): array
    {
        return $producto->gruposModificadores()
            ->get()
            ->filter(function ($vinculo) use ($permitidos): bool {
                if ($vinculo->obligatorio !== null) {
                    return (bool) $vinculo->obligatorio;
                }

                $grupo = $permitidos->get($vinculo->grupos_modificadores_id);

                return $grupo !== null && $grupo->obligatorio;
            })
            ->map(fn ($vinculo) => $permitidos->get($vinculo->grupos_modificadores_id))
            ->filter()
            ->values()
            ->all();
    }
}