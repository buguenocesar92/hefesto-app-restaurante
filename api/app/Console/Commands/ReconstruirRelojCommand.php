<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Cocina\RelojDeCocina;
use App\Services\Tenancy\ContextoRestaurante;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Repuebla el reloj de cocina desde la base.
 *
 * CUANDO SE CORRE
 * ---------------
 *  - Despues de que Redis se reinicie o se caiga y vuelva vacio.
 *  - A diario, como red de seguridad.
 *
 * POR QUE NO SE CALCULA AL VOLVER
 * -------------------------------
 * Podria "arreglarse" solo mostrando en pantalla `now() - enviado_at` leido de
 * la base. Se eligio no hacerlo a proposito: esa es exactamente la consulta que
 * esta aplicacion evita hacer varias veces por minuto, y volver a ella por
 * comodidad devuelve el problema. Reconstruir es una operacion de una vez y
 * explicita.
 */
class ReconstruirRelojCommand extends Command
{
    protected $signature = 'restaurante:reconstruir-reloj {--restaurante= : Limitar a un restaurante en formato uuid}';

    protected $description = 'Repuebla el reloj de cocina en Redis desde las marcas de tiempo de la base.';

    public function handle(RelojDeCocina $reloj, ContextoRestaurante $contexto): int
    {
        $restaurante = $this->option('restaurante');

        if ($restaurante !== null) {
            $total = $contexto->ejecutarAislado((string) $restaurante, fn (): int => $reloj->reconstruirDesdeBase((string) $restaurante));

            $this->info('Se reconstruyeron ' . $total . ' items del restaurante ' . $restaurante . '.');

            return self::SUCCESS;
        }

        // Sin filtro hay que recorrer los restaurantes activos. Son pocos y cada
        // uno va con su propio contexto: el contexto de uno nunca se aplica al
        // siguiente, porque es de alcance de transaccion.
        $restaurantes = DB::table('restaurantes')->where('activo', true)->pluck('id');
        $total = 0;

        foreach ($restaurantes as $id) {
            $total += $contexto->ejecutarAislado((string) $id, fn (): int => $reloj->reconstruirDesdeBase((string) $id));

            $this->line('  ' . $id . ': ' . $total . ' items acumulados');
        }

        $this->info('Se reconstruyeron ' . $total . ' items en total.');

        return self::SUCCESS;
    }
}