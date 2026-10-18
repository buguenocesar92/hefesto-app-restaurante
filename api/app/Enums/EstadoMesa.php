<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Estado de una mesa en el mapa del salon.
 *
 * `por_cerrar` existe por un motivo concreto: cuando el comensal pide la
 * cuenta, la mesa deja de aceptar pedidos pero sigue ocupada. Si solo
 * tuvieran `libre` y `ocupada`, habria que elegir entre marcarla libre
 * (mentira: la gente sigue sentada) o mantenerla ocupada (y el salon no sabe
 * que tiene una cuenta pendiente de cobro). Es un estado chico que evita una
 * mentira en el mapa.
 */
enum EstadoMesa: string
{
    case Libre = 'libre';
    case Ocupada = 'ocupada';
    case PorCerrar = 'por_cerrar';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Libre => 'Libre',
            self::Ocupada => 'Ocupada',
            self::PorCerrar => 'Por cerrar',
        };
    }
}