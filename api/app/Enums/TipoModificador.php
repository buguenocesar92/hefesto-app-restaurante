<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Tipo de modificador.
 *
 * La distincion entre `remocion` y `sin_costo` no es academica: una remocion
 * tiene que apuntar a un ingrediente que este EN LA RECETA del producto, y
 * `sin_costo` no. Si se mezclaran, el sistema aceptaria "sin cebolla" en un
 * plato sin cebolla y el mesero no tendria forma de saber si el sistema lo
 * valido o no.
 */
enum TipoModificador: string
{
    /** Suma precio: extra queso, extra palta. */
    case Agregado = 'agregado';

    /** No cambia el precio: sin hielo, sin sal. */
    case SinCosto = 'sin_costo';

    /** Quita un ingrediente que esta en la receta: sin cebolla. */
    case Remocion = 'remocion';

    /** Cambia por otro: vino tinto en vez de blanco. */
    case Cambio = 'cambio';

    public function admiteIngrediente(): bool
    {
        return $this === self::Remocion || $this === self::Cambio;
    }

    /** Si el modificador obliga a la cocina a hacer algo distinto al item base. */
    public function afectaCocina(): bool
    {
        return $this !== self::SinCosto;
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::Agregado => 'Agregado',
            self::SinCosto => 'Sin costo',
            self::Remocion => 'Remoción',
            self::Cambio => 'Cambio',
        };
    }
}