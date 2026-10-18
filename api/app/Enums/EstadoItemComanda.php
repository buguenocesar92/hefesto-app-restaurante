<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Estado de un item de la comanda, o sea, de un pedido que viaja a cocina.
 *
 * El estado vive en el ITEM y no en la comanda porque los pedidos se
 * preparan en paralelo y terminan en momentos distintos. Una comanda con tres
 * platos puede tener uno entregado, uno en preparacion y uno recien enviado.
 * Si el estado fuera de la comanda, la cocina tendria que declarar la comanda
 * entera cuando uno sale, y el salon dejaria de saber que falta algo.
 */
enum EstadoItemComanda: string
{
    /** En la comanda del salon, todavia no visible en cocina. */
    case Pendiente = 'pendiente';

    /** Visible en pantalla de cocina. Empieza a contar el tiempo. */
    case Enviado = 'enviado';

    /** Alguien tomo el pedido y esta trabajando en el. */
    case EnPreparacion = 'en_preparacion';

    /**
     * En el pasillo, esperando que alguien lo lleve a la mesa.
     *
     * Es el estado que mas problemas genera y el que motiva la pantalla por
     * tiempo: un plato listo que nadie reclama no se ve solo. Sigue contando
     * tiempo, y la pantalla lo ordena primero.
     */
    case Listo = 'listo';

    /** Llego al comensal. */
    case Entregado = 'entregado';

    /** Se elimino antes de la cuenta. */
    case Anulado = 'anulado';

    /** Cuenta el tiempo en pantalla de cocina. */
    public function estaEnPantalla(): bool
    {
        return $this === self::Enviado || $this === self::EnPreparacion || $this === self::Listo;
    }

    public function admiteAnulacion(): bool
    {
        return ! $this->esTerminal();
    }

    public function esTerminal(): bool
    {
        return $this === self::Entregado || $this === self::Anulado;
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::Pendiente => 'Pendiente',
            self::Enviado => 'Enviado a cocina',
            self::EnPreparacion => 'En preparación',
            self::Listo => 'Listo',
            self::Entregado => 'Entregado',
            self::Anulado => 'Anulado',
        };
    }
}