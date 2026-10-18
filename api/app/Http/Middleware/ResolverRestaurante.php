<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Tenancy\ContextoRestaurante;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fija el restaurante de la peticion para que la RLS de PostgreSQL sepa a quien
 * pertenece cada fila.
 *
 * Se aplica al grupo `web` ANTES del middleware `auth` de la ruta, asi que
 * resuelve el usuario por su cuenta con el guard `web`, que ya lee la sesion.
 *
 * Peticiones sin sesion (inicio de sesion, salud) pasan sin transaccion. Si
 * esas rutas tocaran una tabla protegida, `public.app_tenant_id()` lanzaria
 * excepcion y la peticion fallaria: el aislamiento falla cerrado, nunca
 * abierto.
 */
class ResolverRestaurante
{
    public function __construct(private readonly ContextoRestaurante $contexto) {}

    public function handle(Request $peticion, Closure $siguiente): Response
    {
        $usuario = auth()->guard('web')->user();

        if ($usuario === null) {
            return $siguiente($peticion);
        }

        if ($usuario->tenant_id === null) {
            // Una cuenta sin restaurante no puede operar. Se corta antes de
            // tocar la base de datos.
            abort(403, 'La cuenta no esta asociada a ningun restaurante.');
        }

        return $this->contexto->ejecutarAislado(
            $usuario->tenant_id,
            static fn () => $siguiente($peticion)
        );
    }
}