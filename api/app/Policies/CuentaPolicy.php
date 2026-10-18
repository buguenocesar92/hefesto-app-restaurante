<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Rol;
use App\Models\Cuenta;
use App\Models\Usuario;

/** Autorizacion sobre la cuenta. El mesero pide, el cajero cobra. */
class CuentaPolicy
{
    public function viewAny(Usuario $usuario): bool
    {
        return $usuario->es(Rol::Mesero, Rol::Cajero, Rol::Administrador);
    }

    public function ver(Usuario $usuario, Cuenta $cuenta): bool
    {
        return $this->viewAny($usuario);
    }

    /** Dividir la cuenta entre comensales. */
    public function dividir(Usuario $usuario, Cuenta $cuenta): bool
    {
        return $usuario->es(Rol::Mesero, Rol::Cajero, Rol::Administrador);
    }

    /** Registrar un pago: solo caja. */
    public function cobrar(Usuario $usuario, Cuenta $cuenta): bool
    {
        return $usuario->es(Rol::Cajero, Rol::Administrador) && $cuenta->estado !== 'cobrada';
    }
}