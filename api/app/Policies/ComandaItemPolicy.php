<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Rol;
use App\Models\ComandaItem;
use App\Models\Usuario;

/**
 * Autorizacion sobre un item de la comanda.
 *
 * ANULAR UN ITEM TIENE TRES CANDADOS, Y LOS TRES IMPORTAN
 * ------------------------------------------------------
 * 1. La comanda tiene que admitir anulacion. Si ya esta cobrada, no.
 * 2. El item tiene que admitir anulacion. Un item entregado o anulado ya no.
 * 3. El rol tiene que poder anular.
 *
 * Falta el cuarto candado, que no es de autorizacion sino de la maquina de
 * estados: un item en estado `listo` no se anula aunque la comanda siga
 * abierta, porque ya esta emplatado y en el pasillo. Ese lo revisa
 * App\Services\Comandas\MaquinaDeEstadosComanda.
 */
class ComandaItemPolicy
{
    public function view(Usuario $usuario, ComandaItem $item): bool
    {
        return $usuario->activo;
    }

    /**
     * Mover el item por la maquina de estados de cocina.
     *
     * PENDIENTE, documentado y no resuelto: no se modela a que estacion esta
     * asignado cada cocinero, asi que un cocinero puede marcar el estado de
     * un item de otra estacion. En la practica el tablet o la pantalla esta
     * junto a la estacion, pero el permiso no esta limitado en el servidor.
     * Es una decision consciente, no un olvido, y conviene cerrarla cuando se
     * definan los turnos de cocina.
     */
    public function cambiarEstado(Usuario $usuario, ComandaItem $item): bool
    {
        return $usuario->es(Rol::Cocinero, Rol::JefeCocina, Rol::Administrador)
            && $item->estado->estaEnPantalla();
    }

    /** Marcar como entregado: lo hace quien lleva el plato, que es salon. */
    public function entregar(Usuario $usuario, ComandaItem $item): bool
    {
        return $usuario->es(Rol::Mesero, Rol::Cajero, Rol::JefeCocina, Rol::Administrador)
            && $item->estado->value === 'listo';
    }

    public function anular(Usuario $usuario, ComandaItem $item): bool
    {
        if (! $item->comanda?->estado->admiteAnulacion()) {
            return false;
        }

        if (! $item->estado->admiteAnulacion()) {
            return false;
        }

        return $usuario->es(Rol::Mesero, Rol::Cajero, Rol::JefeCocina, Rol::Administrador);
    }
}