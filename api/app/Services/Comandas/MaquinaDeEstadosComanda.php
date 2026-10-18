<?php

declare(strict_types=1);

namespace App\Services\Comandas;

use App\Enums\EstadoComanda;
use App\Enums\EstadoItemComanda;
use DomainException;

/**
 * Maquina de estados de la comanda y de sus items.
 *
 * Concentrar las transiciones aqui tiene un motivo concreto: la regla que
 * protege el cobro no puede depender de que cada endpoint se acuerde. Si
 * "anular" esta disponible en un metodo y no en otro, la excepcion aparece a
 * las dos de la manana, con un mesero esperando.
 *
 * TRANSICIONES DE ITEM
 * --------------------
 *   pendiente -> enviado | anulado
 *   enviado   -> en_preparacion | listo | anulado
 *   en_preparacion -> listo | anulado
 *   listo     -> entregado
 *   entregado -> (terminal)
 *   anulado   -> (terminal)
 *
 * Dos decisiones que parecen arbitrarias y no lo son:
 *
 *  1. `enviado -> listo` esta permitido sin pasar por `en_preparacion`. En un
 *     real, el cocinero marca el terminado y el sistema marca "en
 *     preparacion" cuando alguien toma el pedido; entre las dos cosas hay un
 *     boton, no dos. Obrigar a apretar dos veces hace que la gente apriete mal
 *     y el dato de preparacion se perda.
 *
 *  2. `listo -> anulado` NO esta permitido. Un plato que ya esta listo y
 *     emplatado no se cancela: se cobra. Anular en ese punto es un problema de
 *     caja, no de cocina, y se resuelve en el POS. Permitirlo dejaria el
 *     pasillo con un plato que alguien cobro y alguien mas devolvio.
 */
class MaquinaDeEstadosComanda
{
    /**
     * Transiciones permitidas de la comanda.
     *
     * `por_cobrar -> abierta` existe porque el caso es real: el comensal pide
     * la cuenta, recuerda que falta una entrada, y el mesero tiene que
     * volver a abrir. Lo que no se permite es reabrir una comanda cobrada.
     *
     * @var array<string, list<string>>
     */
    private const TRANSICIONES_COMANDA = [
        'abierta' => ['por_cobrar', 'anulada'],
        'por_cobrar' => ['abierta', 'cobrada', 'anulada'],
        'cobrada' => [],
        'anulada' => [],
    ];

    /**
     * Transiciones permitidas del item.
     *
     * @var array<string, list<string>>
     */
    private const TRANSICIONES_ITEM = [
        'pendiente' => ['enviado', 'anulado'],
        'enviado' => ['en_preparacion', 'listo', 'anulado'],
        'en_preparacion' => ['listo', 'anulado'],
        'listo' => ['entregado'],
        'entregado' => [],
        'anulado' => [],
    ];

    /**
     * @throws DomainException si la transicion no esta permitida.
     */
    public function moverComanda(EstadoComanda $desde, EstadoComanda $hacia): void
    {
        $permitidas = self::TRANSICIONES_COMANDA[$desde->value] ?? [];

        if (! in_array($hacia->value, $permitidas, true)) {
            throw new DomainException($this->explicacion(
                'comanda',
                $desde->etiqueta(),
                $hacia->etiqueta()
            ));
        }
    }

    /**
     * @throws DomainException si la transicion no esta permitida.
     */
    public function moverItem(EstadoItemComanda $desde, EstadoItemComanda $hacia): void
    {
        $permitidas = self::TRANSICIONES_ITEM[$desde->value] ?? [];

        if (! in_array($hacia->value, $permitidas, true)) {
            throw new DomainException($this->explicacion('item', $desde->etiqueta(), $hacia->etiqueta()));
        }
    }

    /** @return list<string> estados a los que se puede ir desde `$desde`. */
    public function destinosItem(EstadoItemComanda $desde): array
    {
        return array_map(
            static fn (string $valor): EstadoItemComanda => EstadoItemComanda::from($valor),
            self::TRANSICIONES_ITEM[$desde->value] ?? []
        );
    }

    private function explicacion(string $tipo, string $desde, string $hacia): string
    {
        if ($tipo === 'comanda' && $desde === 'Cobrada') {
            return 'Una comanda cobrada no se anula desde este sistema: la reversión se hace en el punto de venta que emitió el cobro.';
        }

        if ($tipo === 'item' && $desde === 'Listo') {
            return 'Un item listo ya no se anula: se cobra. Si el comensal devuelve el plato, es un problema de caja y se resuelve en el punto de venta.';
        }

        return 'No se puede pasar de "' . $desde . '" a "' . $hacia . '".';
    }
}