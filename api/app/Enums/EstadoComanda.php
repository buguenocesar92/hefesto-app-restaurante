<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Estado de la comanda.
 *
 * La transicion que tiene reglas duras es la anulacion: desde `cobrada` NO SE
 * ANULA. Un cobro ya realizado se revierte en el punto de venta que lo emitio,
 * con su propia autorizacion y su propia bitacora. Adivinar aqui una
 * "anulacion de comanda" sin el POS seria inventar una devolucion que nadie
 * registro.
 */
enum EstadoComanda: string
{
    case Abierta = 'abierta';
    case PorCobrar = 'por_cobrar';
    case Cobrada = 'cobrada';
    case Anulada = 'anulada';

    /** Estado en el que la comanda todavia admite cambios. */
    public function admiteItems(): bool
    {
        return $this === self::Abierta;
    }

    /** Estado desde el que se puede anular. `cobrada` queda excluida a proposito. */
    public function admiteAnulacion(): bool
    {
        return $this === self::Abierta || $this === self::PorCobrar;
    }

    public function admiteCobro(): bool
    {
        return $this === self::PorCobrar;
    }

    public function esTerminal(): bool
    {
        return $this === self::Cobrada || $this === self::Anulada;
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::Abierta => 'Abierta',
            self::PorCobrar => 'Por cobrar',
            self::Cobrada => 'Cobrada',
            self::Anulada => 'Anulada',
        };
    }
}