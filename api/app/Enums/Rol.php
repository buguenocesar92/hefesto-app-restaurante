<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Roles del personal.
 *
 * La separacion que importa, y que el dueño reconoce de inmediato, es que
 * QUIEN ABRE CAJA NO ES QUIEN EDITA RECETAS. Con un solo permiso de
 * "administrador", el mesero con clave de gerente termina cambiando precios
 * porque nadie separo los dos poderes. Con roles explicitos, abrir caja y tocar
 * recetas son permisos distintos y el AUDITORIO (comanda_eventos) muestra
 * quien hizo cada cosa.
 */
enum Rol: string
{
    case Administrador = 'administrador';

    /** Abre caja, cobra, divide cuentas. NO edita recetas ni usuarios. */
    case Cajero = 'cajero';

    /** Abre comandas, agrega items, pide cuentas, anula items no cobrados. */
    case Mesero = 'mesero';

    /** Cambia el estado de los items en su estacion. No ve la cuenta. */
    case Cocinero = 'cocinero';

    /** Todo lo de cocina, mas la vista completa de tiempos por estacion. */
    case JefeCocina = 'jefe_cocina';

    /** Rol de administracion del local. */
    public function esAdministracion(): bool
    {
        return $this === self::Administrador;
    }

    /** Puede mover items por la maquina de estados de cocina. */
    public function operaCocina(): bool
    {
        return $this === self::Cocinero || $this === self::JefeCocina;
    }

    /** Puede mover la comanda hacia el cobro. */
    public function operaCaja(): bool
    {
        return $this === self::Cajero || $this === self::Administrador;
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::Administrador => 'Administración',
            self::Cajero => 'Caja',
            self::Mesero => 'Mesero',
            self::Cocinero => 'Cocina',
            self::JefeCocina => 'Jefe de cocina',
        };
    }

    /** @return list<string> */
    public static function valores(): array
    {
        return array_map(static fn (self $rol): string => $rol->value, self::cases());
    }
}