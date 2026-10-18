<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Rol;
use App\Models\Mesa;
use App\Models\Usuario;

/**
 * Autorizacion sobre el salon.
 *
 * El salon se lee y se escribe distinto: cualquiera del turno ve el mapa,
 * pero cambiar la disposicion de mesas o sus capacidades es administracion.
 * Un mesero que puede mover mesas del mapa termina moviendo las que quiere.
 */
class MesaPolicy
{
    public function viewAny(Usuario $usuario): bool
    {
        return $usuario->activo;
    }

    /** Crear, combinar o dividir mesas: solo administracion. */
    public function administrar(Usuario $usuario): bool
    {
        return $usuario->es(Rol::Administrador);
    }

    /** Marcar por cerrar cuando llega la cuenta. */
    public function pedirCuenta(Usuario $usuario, Mesa $mesa): bool
    {
        return $usuario->es(Rol::Mesero, Rol::Cajero, Rol::Administrador);
    }
}