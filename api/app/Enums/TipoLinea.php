<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Que se vende. No es lo mismo que en que estacion se prepara.
 */
enum TipoLinea: string
{
    case Comida = 'comida';
    case Bebida = 'bebida';
    case Postre = 'postre';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Comida => 'Comida',
            self::Bebida => 'Bebida',
            self::Postre => 'Postre',
        };
    }
}