<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Rol;
use App\Models\Turno;
use App\Models\Usuario;

/**
 * Autorizacion sobre turnos.
 *
 * Un turno NO es un turno de una persona: es la franja en que el local abre.
 * Solo administracion crea o cambia ventanas, y nadie que no sea administracion
 * se asigna a si mismo.
 */
class TurnoPolicy
{
    public function viewAny(Usuario $usuario): bool
    {
        return $usuario->activo;
    }

    public function view(Usuario $usuario, Turno $turno): bool
    {
        return $usuario->activo;
    }

    public function administrar(Usuario $usuario): bool
    {
        return $usuario->es(Rol::Administrador);
    }

    public function asignar(Usuario $usuario): bool
    {
        return $usuario->es(Rol::Administrador);
    }
}