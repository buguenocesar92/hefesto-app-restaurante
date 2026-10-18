<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Turno;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exige que haya un turno activo para las operaciones que mueven dinero o
 * generan trabajo.
 *
 * Que la pantalla de cocina NO la lleve es deliberado: cuando el local cierra,
 * quedan pedidos en preparacion y alguien tiene que poder marcarlos y entregarlos.
 * Bloquear la cocina a las 23:30 dejaria platos listos en el pasillo sin
 * registro.
 */
class ExigirTurnoActivo
{
    public function handle(Request $peticion, Closure $siguiente): Response
    {
        $usuario = auth()->user();

        if ($usuario === null) {
            return $siguiente($peticion);
        }

        $turnoActual = Turno::query()
            ->where('activo', true)
            ->get()
            ->first(fn (Turno $turno): bool => $turno->contiene(now()));

        if ($turnoActual === null) {
            abort(422, 'No hay un turno activo. Pide al administrador que abra el turno del local antes de operar.');
        }

        app()->instance('turno.actual', $turnoActual);

        return $siguiente($peticion);
    }
}