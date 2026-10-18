<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Formas de pago que registra el salon.
 *
 * Los codigos siguen la convencion del POS del cliente; el mapeo exacto esta
 * pendiente de confirmar contra la documentacion de ese POS, que es la razon
 * por la que la integracion NO esta implementada. Ver docs/09-API.md.
 */
enum FormaPago: string
{
    case Efectivo = 'efectivo';
    case TarjetaDebito = 'tarjeta_debito';
    case TarjetaCredito = 'tarjeta_credito';
    case Transferencia = 'transferencia';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Efectivo => 'Efectivo',
            self::TarjetaDebito => 'Tarjeta de débito',
            self::TarjetaCredito => 'Tarjeta de crédito',
            self::Transferencia => 'Transferencia',
        };
    }
}