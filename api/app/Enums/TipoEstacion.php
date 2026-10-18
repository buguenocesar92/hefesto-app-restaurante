<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Tipo de estacion de trabajo.
 *
 * Es lo que hace que el tiempo en pantalla signifique algo: un pedido en
 * `bebida` y uno en `comida` no se miden contra el mismo umbral.
 */
enum TipoEstacion: string
{
    case Comida = 'comida';
    case Bebida = 'bebida';
    case Postura = 'postura';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Comida => 'Cocina caliente',
            self::Bebida => 'Barra',
            self::Postura => 'Postrería',
        };
    }
}