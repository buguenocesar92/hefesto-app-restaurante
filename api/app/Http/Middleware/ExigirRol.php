<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Rol;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restringe una ruta a ciertos roles.
 *
 * Es un filtro de ENTRADA, no una autorizacion: quien decide de verdad si una
 * accion es permitida sobre un recurso concreto es la Policy correspondiente.
 * Este middleware existe para que un cocinero no alcance ni siquiera la lista
 * de cajas, no solo para impedirle cobrar.
 */
class ExigirRol
{
    /** @param  list<Rol>  $roles  */
    public function handle(Request $peticion, Closure $siguiente, string ...$roles): Response
    {
        $usuario = auth()->user();

        if ($usuario === null) {
            abort(401);
        }

        $permitidos = array_map(static fn (string $rol): Rol => Rol::from($rol), $roles);

        if (! $usuario->es(...$permitidos)) {
            abort(403, 'Tu rol no tiene permiso para esta operacion.');
        }

        return $siguiente($peticion);
    }
}