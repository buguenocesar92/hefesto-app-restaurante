<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Rol;
use App\Models\Producto;
use App\Models\Usuario;

/**
 * Autorizacion sobre el catalogo.
 *
 * AQUI ESTA LA SEPARACION QUE EL DUENO ENTIENDE DE INMEDIATO: QUIEN ABRE CAJA
 * NO ES QUIEN EDITA RECETAS.
 *
 * El cajero ve el menu, porque lo necesita para cobrar, pero no lo edita. El
 * administrador edita recetas y precios. Si se mezclaran los dos poderes, el
 * mesero con acceso a caja terminaria cambiando precios, y el cambio aparece
 * en la bitacora con su nombre. Separarlos no es segurismo: es que el dato
 * tenga un responsable.
 */
class ProductoPolicy
{
    public function viewAny(Usuario $usuario): bool
    {
        return $usuario->activo;
    }

    public function view(Usuario $usuario, Producto $producto): bool
    {
        return $usuario->activo;
    }

    /** Editar precios, recetas, ingredientes y modificadores. */
    public function administrar(Usuario $usuario): bool
    {
        return $usuario->es(Rol::Administrador);
    }

    /** Cambiar un precio es lo mas sensible: queda en la bitacora del item. */
    public function cambiarPrecio(Usuario $usuario): bool
    {
        return $usuario->es(Rol::Administrador);
    }
}